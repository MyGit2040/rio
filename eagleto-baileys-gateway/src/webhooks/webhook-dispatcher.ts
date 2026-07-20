import type { Prisma } from '@prisma/client'

import { env } from '../config/env.js'
import { contextLogger } from '../config/logger.js'
import { prisma } from '../database/client.js'
import { decryptOptional } from '../security/encryption.js'
import type { MessageMetadata, WebhookEnvelope, WebhookEventType } from '../types/index.js'
import { signWebhook } from './webhook-signer.js'

/**
 * Outbound event delivery to Laravel.
 *
 * The subsystem is split in two on purpose:
 *
 *   enqueueWebhook  — persists the event, and does nothing else.
 *   deliverWebhook  — attempts one HTTP delivery of an already-persisted event.
 *
 * Nothing in the gateway calls Laravel inline. A socket callback that had to
 * await an HTTP round trip would stall the WhatsApp event loop when Laravel is
 * slow, and would lose the event outright when Laravel is down. Persist first,
 * deliver later: an outage becomes a delay, never a loss.
 */

/** Mirrors the WebhookStatus enum in prisma/schema.prisma. */
export const WEBHOOK_STATUSES = [
  'PENDING',
  'DELIVERING',
  'DELIVERED',
  'RETRY_WAIT',
  'DEAD_LETTER',
] as const

export type WebhookStatus = (typeof WEBHOOK_STATUSES)[number]

/** Statuses a worker may claim for a delivery attempt. */
export const CLAIMABLE_STATUSES: readonly WebhookStatus[] = ['PENDING', 'RETRY_WAIT']

/**
 * Retry schedule in seconds, indexed by attempts already made.
 *
 * Front-loaded so a brief Laravel restart is absorbed within a minute, then
 * widening so a prolonged outage does not hammer a service that is trying to
 * come back up. Attempts past the end of the schedule sit at the cap.
 */
export const BACKOFF_SCHEDULE_SECONDS = [10, 30, 60, 300, 900, 3600] as const

export const MAX_BACKOFF_SECONDS = 3600

/**
 * Jitter spreads a backlog out. Without it, every event queued during an outage
 * becomes due in the same instant and the recovering service is hit by the
 * whole backlog at once — the thundering herd that caused the outage twice.
 */
const JITTER_RATIO = 0.2

/** Cap on the response text stored in lastError, so an HTML error page cannot fill the column. */
const MAX_ERROR_LENGTH = 500

export interface EnqueueWebhookInput {
  eventType: WebhookEventType
  instanceId?: string | null
  data: Record<string, unknown>
  metadata?: MessageMetadata
  occurredAt?: Date
}

export interface DeliveryResult {
  delivered: boolean
  statusCode?: number
  error?: string
}

/**
 * Delay before the next attempt.
 *
 * `attempt` is the number of attempts already made: after the first failure
 * (attempt = 1) the next try is ~10s away. Exported for direct testing — a
 * retry curve buried in a private closure is a curve nobody ever verifies.
 */
export function backoffDelaySeconds(attempt: number): number {
  const index = Math.max(0, Math.floor(attempt) - 1)
  const base = BACKOFF_SCHEDULE_SECONDS[index] ?? MAX_BACKOFF_SECONDS

  const jitter = base * JITTER_RATIO * (Math.random() * 2 - 1)

  // Clamped to the cap so jitter can never push a delay past the stated
  // ceiling, and to >=1s so a delay can never round down to "immediately".
  return Math.min(MAX_BACKOFF_SECONDS, Math.max(1, Math.round(base + jitter)))
}

/**
 * Persist an event. This is the ONLY durability point — it must not perform
 * network I/O, and must not depend on Laravel being reachable.
 */
export async function enqueueWebhook(input: EnqueueWebhookInput): Promise<string> {
  const occurredAt = input.occurredAt ?? new Date()

  const event = await prisma.webhookEvent.create({
    data: {
      instanceId: input.instanceId ?? null,
      eventType: input.eventType,
      // Cast at the storage boundary: callers hand us `unknown`-valued records,
      // while Prisma's Json column wants a proven-serialisable type. The values
      // are JSON by contract — they are about to be sent as a JSON webhook.
      payload: input.data as Prisma.InputJsonObject,
      metadata: (input.metadata ?? {}) as Prisma.InputJsonObject,
      status: 'PENDING',
      attempts: 0,
      occurredAt,
      // Due immediately; the worker picks it up on its next pass.
      nextAttemptAt: new Date(),
    },
    select: { id: true },
  })

  return event.id
}

interface DeliveryTarget {
  url: string
  secret: string
  /** True when the instance overrode the platform default, for logging. */
  perInstance: boolean
}

interface InstanceWebhookOverride {
  webhookUrl: string | null
  webhookSecretEnc: string | null
}

/**
 * Per-instance overrides fall back independently: an instance may point at its
 * own endpoint while still using the platform secret, so the two columns are
 * resolved separately rather than as a pair.
 */
function resolveTarget(instance: InstanceWebhookOverride | null | undefined): DeliveryTarget {
  const e = env()

  const url = instance?.webhookUrl ?? e.LARAVEL_WEBHOOK_URL

  // Throws DecryptionError on a key change or a tampered row. That propagates
  // into the normal failure path below: the event retries and ultimately
  // dead-letters with the cause named, rather than being signed with the wrong
  // secret and rejected by Laravel as a forgery.
  const secret = decryptOptional(instance?.webhookSecretEnc) ?? e.WEBHOOK_SIGNING_SECRET

  return {
    url,
    secret,
    perInstance: Boolean(instance?.webhookUrl ?? instance?.webhookSecretEnc),
  }
}

function truncate(value: string): string {
  return value.length > MAX_ERROR_LENGTH ? `${value.slice(0, MAX_ERROR_LENGTH)}…` : value
}

function describeError(error: unknown): string {
  if (error instanceof Error) {
    // AbortSignal.timeout rejects with a bare "The operation was aborted",
    // which tells an operator nothing about which limit was hit.
    if (error.name === 'TimeoutError' || error.name === 'AbortError') {
      return `Webhook request timed out after ${env().WEBHOOK_TIMEOUT_MS}ms.`
    }

    return `${error.name}: ${error.message}`
  }

  return String(error)
}

/**
 * Attempt one delivery of a persisted event.
 *
 * Safe to call concurrently only if the caller has claimed the row first (see
 * WebhookWorker) — this function does not itself guard against two nodes
 * delivering the same event.
 */
export async function deliverWebhook(eventId: string): Promise<DeliveryResult> {
  const log = contextLogger({ eventId })

  const event = await prisma.webhookEvent.findUnique({
    where: { id: eventId },
    include: {
      instance: { select: { webhookUrl: true, webhookSecretEnc: true, externalInstanceId: true } },
    },
  })

  if (!event) {
    return { delivered: false, error: 'Webhook event not found.' }
  }

  // A replayed or double-claimed row that already landed must not be sent
  // again — at-least-once is the contract, but not gratuitously.
  if (event.status === 'DELIVERED') {
    return { delivered: true, ...(event.lastStatusCode ? { statusCode: event.lastStatusCode } : {}) }
  }

  const envelope: WebhookEnvelope = {
    event_id: event.id,
    event_type: event.eventType as WebhookEventType,
    event_version: event.eventVersion,
    occurred_at: event.occurredAt.toISOString(),
    // The EXTERNAL id — the name Laravel generated and stores as
    // whatsapp_instances.instance_name. Laravel has never seen the gateway's
    // internal cuid, so sending that made every event unmatchable and silently
    // discarded: statuses never advanced and devices never reported ready.
    // The internal id still travels as gateway_instance_id for support.
    instance_id: event.instance?.externalInstanceId ?? event.instanceId,
    data: {
      ...((event.payload ?? {}) as Record<string, unknown>),
      gateway_instance_id: event.instanceId,
    },
    metadata: (event.metadata ?? {}) as MessageMetadata,
  }

  let target: DeliveryTarget

  try {
    target = resolveTarget(event.instance)
  } catch (error) {
    return recordFailure(event.id, event.attempts, describeError(error), undefined, log)
  }

  // Serialise EXACTLY ONCE. The signature covers these bytes and these bytes
  // are what is sent — re-serialising for the request would risk a different
  // key order or number formatting, and Laravel would reject a signature that
  // was computed over a string it never received.
  const body = JSON.stringify(envelope)
  const timestamp = Math.floor(Date.now() / 1000).toString()
  const signature = signWebhook(target.secret, timestamp, body)

  try {
    const response = await fetch(target.url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Eagleto-Event-ID': event.id,
        'X-Eagleto-Timestamp': timestamp,
        'X-Eagleto-Signature': signature,
      },
      body,
      signal: AbortSignal.timeout(env().WEBHOOK_TIMEOUT_MS),
    })

    if (response.ok) {
      await prisma.webhookEvent.update({
        where: { id: event.id },
        data: {
          status: 'DELIVERED',
          attempts: event.attempts + 1,
          deliveredAt: new Date(),
          lastStatusCode: response.status,
          lastError: null,
        },
      })

      log.info(
        { eventType: event.eventType, statusCode: response.status, perInstance: target.perInstance },
        'Webhook delivered.',
      )

      return { delivered: true, statusCode: response.status }
    }

    return recordFailure(
      event.id,
      event.attempts,
      `HTTP ${response.status}: ${truncate(await readBodySafely(response))}`,
      response.status,
      log,
    )
  } catch (error) {
    return recordFailure(event.id, event.attempts, describeError(error), undefined, log)
  }
}

/**
 * A non-2xx body usually carries the reason (a validation error, a stack
 * trace). Reading it must never itself fail the delivery bookkeeping.
 */
async function readBodySafely(response: Response): Promise<string> {
  try {
    return (await response.text()).trim()
  } catch {
    return '<unreadable response body>'
  }
}

async function recordFailure(
  eventId: string,
  previousAttempts: number,
  error: string,
  statusCode: number | undefined,
  log: ReturnType<typeof contextLogger>,
): Promise<DeliveryResult> {
  const attempts = previousAttempts + 1
  const exhausted = attempts >= env().WEBHOOK_MAX_ATTEMPTS

  const delaySeconds = exhausted ? 0 : backoffDelaySeconds(attempts)

  await prisma.webhookEvent.update({
    where: { id: eventId },
    data: {
      status: exhausted ? 'DEAD_LETTER' : 'RETRY_WAIT',
      attempts,
      nextAttemptAt: new Date(Date.now() + delaySeconds * 1000),
      lastStatusCode: statusCode ?? null,
      lastError: truncate(error),
    },
  })

  if (exhausted) {
    // Dead-lettering is a data-loss-adjacent event an operator must see, and
    // production runs at LOG_LEVEL=error — a warning here would be invisible.
    log.error({ attempts, statusCode, error }, 'Webhook dead-lettered after exhausting attempts.')
  } else {
    log.warn({ attempts, statusCode, error, retryInSeconds: delaySeconds }, 'Webhook delivery failed; scheduled retry.')
  }

  return {
    delivered: false,
    ...(statusCode === undefined ? {} : { statusCode }),
    error,
  }
}
