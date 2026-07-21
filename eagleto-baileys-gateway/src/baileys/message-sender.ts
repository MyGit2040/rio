import { createHash } from 'node:crypto'

import { env } from '../config/env.js'
import { contextLogger } from '../config/logger.js'
import { prisma } from '../database/client.js'
import { groupActionThrottle } from '../instances/group-throttle.js'
import { reconnectPacer } from '../instances/reconnect-pacer.js'
import { sendCooldowns } from '../instances/send-cooldown.js'
import { findInstance } from '../instances/resolve.js'
import { serialQueues } from '../instances/serial-queue.js'
import { healthWarningWebhook, sessionHealth } from '../monitoring/session-health.js'
import type { MessageMetadata, OutgoingMessageKind, SendAcceptance } from '../types/index.js'
import { enqueueWebhook } from '../webhooks/webhook-dispatcher.js'

import type { BaileysSocketHandle } from './adapter/types.js'
import { isGroupJid, toJid } from './jid-utils.js'
import { planTyping } from './presence-choreographer.js'
import { socketManager } from './socket-manager.js'

/** Local sleep. Deliberately not Baileys' `delay`: this module stays free of any Baileys import. */
const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * The text a human would have "typed" for this message, for presence timing.
 * Text sends carry `text`; media sends carry a `caption`. Everything else
 * (polls, location, contacts, captionless media) has no typed text, so presence
 * simulation is skipped for it.
 */
function typedTextOf(content: Record<string, unknown>): string {
  if (typeof content.text === 'string') {
    return content.text
  }

  if (typeof content.caption === 'string') {
    return content.caption
  }

  return ''
}

/**
 * Show a human-like "typing…" indicator before a text send.
 *
 * Best-effort by design: presence is decoration, so any failure here is logged
 * and swallowed rather than allowed to abort the message it was dressing up.
 * Runs inside the instance's serial queue, so the compose pause also naturally
 * spaces successive sends on the same number.
 */
async function simulateTyping(
  socket: BaileysSocketHandle,
  jid: string,
  content: Record<string, unknown>,
  log: ReturnType<typeof contextLogger>,
): Promise<void> {
  const cfg = env()

  if (!cfg.PRESENCE_SIMULATION_ENABLED) {
    return
  }

  const text = typedTextOf(content)

  if (text === '') {
    return
  }

  const plan = planTyping(text.length, {
    msPerChar: cfg.PRESENCE_MS_PER_CHAR,
    minTypeMs: cfg.PRESENCE_MIN_TYPING_MS,
    maxTypeMs: cfg.PRESENCE_MAX_TYPING_MS,
    thinkMinMs: cfg.PRESENCE_THINK_MIN_MS,
    thinkMaxMs: cfg.PRESENCE_THINK_MAX_MS,
  })

  try {
    if (plan.thinkMs > 0) {
      await sleep(plan.thinkMs)
    }

    await socket.sendPresenceUpdate('composing', jid)
    await sleep(plan.typeMs)
    await socket.sendPresenceUpdate('paused', jid)
  } catch (error) {
    log.warn({ err: error }, 'Presence simulation failed; sending without it')
  }
}

/**
 * The single send coordinator.
 *
 * Every outgoing message passes through here — there is no path from the API
 * straight to Baileys. That is what makes idempotency, per-instance
 * serialisation and status tracking unavoidable rather than best-effort.
 *
 * Timing contract: `acceptSend` does the durable, cheap work (validate, check
 * the idempotency ledger, record ACCEPTED) and returns. Actual transmission
 * happens on the instance's serial queue afterwards, and the outcome reaches
 * Laravel as a webhook. Laravel therefore never waits on WhatsApp.
 */

export class DuplicatePayloadError extends Error {
  constructor(readonly idempotencyKey: string) {
    super(
      `Idempotency key '${idempotencyKey}' was already used with a different message payload. ` +
        `Reusing a key for different content is refused, because it is almost always a bug in the ` +
        `caller rather than an intentional retry.`,
    )
    this.name = 'DuplicatePayloadError'
  }
}

export class InstanceNotSendableError extends Error {
  constructor(
    readonly instanceId: string,
    readonly state: string,
  ) {
    super(
      `Instance ${instanceId} is not ready to send (state: ${state}). ` +
        `The gateway will not silently reroute to another number — reassignment is Laravel's decision.`,
    )
    this.name = 'InstanceNotSendableError'
  }
}

export interface SendRequest {
  instanceId: string
  recipient: string
  kind: OutgoingMessageKind
  idempotencyKey: string
  clientMessageId?: string | undefined
  metadata?: MessageMetadata | undefined
  replyToMessageId?: string | undefined
  /** Provider-agnostic content, built by the route from the validated body. */
  content: Record<string, unknown>
  /**
   * Set for poll sends. The Poll row is created before acceptance so that the
   * transmitted Baileys message — which is what vote decryption needs — can be
   * written straight onto it here, rather than reconciled afterwards.
   */
  pollId?: string | undefined
}

function hashPayload(request: SendRequest): string {
  // Only the parts that define the message itself. clientMessageId and metadata
  // are Laravel bookkeeping and may legitimately differ on a retry.
  return createHash('sha256')
    .update(
      JSON.stringify({
        instanceId: request.instanceId,
        recipient: request.recipient,
        kind: request.kind,
        content: request.content,
        replyToMessageId: request.replyToMessageId ?? null,
      }),
    )
    .digest('hex')
}

/**
 * Fast path: record intent and return. Never performs WhatsApp I/O.
 */
export async function acceptSend(request: SendRequest): Promise<SendAcceptance> {
  /**
   * Normalise the identifier before anything else touches it.
   *
   * Laravel addresses an instance by the name it generated, so `instanceId` may
   * arrive here as either identity. Everything downstream is keyed on the
   * gateway's internal id — the GatewayMessage and WebhookEvent foreign keys,
   * the serial queue, the socket manager — so resolving late would mean an FK
   * insert against a name no row has.
   *
   * It also has to happen BEFORE the payload hash, which includes the instance
   * id: a retry that names the same instance the other way round must hash the
   * same, or an honest retry would be refused as a key reused with different
   * content.
   *
   * When nothing resolves, the original value is carried through unchanged so
   * that an unknown instance still fails exactly where it did before — at the
   * sendability check, as InstanceNotSendableError — rather than turning a
   * duplicate replay for a since-deleted instance into a different error.
   */
  const instance = await findInstance(request.instanceId)
  const send: SendRequest = instance ? { ...request, instanceId: instance.id } : request

  const log = contextLogger({
    instanceId: send.instanceId,
    clientMessageId: send.clientMessageId,
  })

  const payloadHash = hashPayload(send)

  const existing = await prisma.gatewayMessage.findUnique({
    where: { idempotencyKey: send.idempotencyKey },
  })

  if (existing) {
    if (existing.payloadHash !== payloadHash) {
      throw new DuplicatePayloadError(send.idempotencyKey)
    }

    // A genuine retry (Laravel timed out and asked again). Hand back the
    // original record instead of sending a second message.
    log.info(
      { gatewayMessageId: existing.id, status: existing.status },
      'Idempotent replay; returning the original message record',
    )

    return {
      gatewayMessageId: existing.id,
      clientMessageId: existing.clientMessageId ?? undefined,
      status: 'duplicate',
      instanceId: existing.instanceId,
    }
  }

  if (!instance) {
    throw new InstanceNotSendableError(send.instanceId, 'UNKNOWN')
  }

  if (!instance.enabled) {
    throw new InstanceNotSendableError(send.instanceId, 'DISABLED')
  }

  const record = await prisma.gatewayMessage.create({
    data: {
      instanceId: send.instanceId,
      idempotencyKey: send.idempotencyKey,
      clientMessageId: send.clientMessageId ?? null,
      recipient: send.recipient,
      kind: send.kind,
      payloadHash,
      status: 'ACCEPTED',
      metadata: (send.metadata ?? {}) as never,
    },
  })

  await enqueueWebhook({
    eventType: 'message.accepted',
    instanceId: send.instanceId,
    data: {
      gateway_message_id: record.id,
      client_message_id: record.clientMessageId,
      recipient: record.recipient,
      kind: record.kind,
    },
    metadata: send.metadata ?? {},
  })

  // Transmission is deliberately not awaited: the caller gets its 202 now.
  void dispatch(record.id, send).catch((error: unknown) => {
    log.error({ err: error, gatewayMessageId: record.id }, 'Unhandled failure while dispatching a message')
  })

  return {
    gatewayMessageId: record.id,
    clientMessageId: record.clientMessageId ?? undefined,
    status: 'accepted',
    instanceId: record.instanceId,
  }
}

/**
 * Slow path: runs on the instance's serial queue, one message at a time.
 *
 * `request.instanceId` is the internal id — `acceptSend` normalises it before
 * this is reached, which is what lets the queue key and the socket lookup agree
 * with the row the message was written against.
 */
async function dispatch(gatewayMessageId: string, request: SendRequest): Promise<void> {
  const log = contextLogger({ instanceId: request.instanceId, gatewayMessageId })

  await serialQueues.run(request.instanceId, async () => {
    try {
      // Re-checked here, not just at acceptance: a socket can drop between the
      // API call and the message reaching the front of the queue.
      const socket = await socketManager.requireSendableSocket(request.instanceId)

      const jid = toJid(request.recipient)

      // Group-action brake: never let two group sends fire back-to-back on one
      // number. One-to-one sends skip this entirely.
      if (isGroupJid(jid)) {
        const groupWaitMs = groupActionThrottle.waitMs(request.instanceId, env().GROUP_ACTION_COOLDOWN_MS)
        if (groupWaitMs > 0) {
          await sleep(groupWaitMs)
        }
        groupActionThrottle.mark(request.instanceId)
      }

      // Ease a freshly-recovered number back to full rate: for the ramp window
      // after it became sendable again, hold each send by a shrinking extra
      // pause instead of blasting the queued batch at once.
      const extraDelayMs = reconnectPacer.extraDelayMs(request.instanceId)
      if (extraDelayMs > 0) {
        await sleep(extraDelayMs)
      }

      // Human-like "typing…" beat before a text/caption send.
      await simulateTyping(socket, jid, request.content, log)

      const sent = await socket.sendMessage(
        jid,
        request.content,
        request.replyToMessageId ? { quoted: { key: { id: request.replyToMessageId } } } : undefined,
      )

      await prisma.gatewayMessage.update({
        where: { id: gatewayMessageId },
        data: {
          status: 'SENT',
          whatsappMessageId: sent.messageId ?? null,
          sentAt: new Date(),
        },
      })

      // A clean send is recovery evidence — it nudges the risk score down.
      sessionHealth.recordSendSuccess(request.instanceId)

      if (request.pollId) {
        // Votes arrive as encrypted aggregates that are only interpretable
        // against the message WhatsApp actually accepted — it carries the
        // message secret. Persisting the transmitted message here is what makes
        // `resolvePollVote` able to fold in an update later; without it every
        // incoming vote would be unresolvable.
        await prisma.poll.update({
          where: { id: request.pollId },
          data: {
            whatsappPollMessageId: sent.messageId ?? null,
            encPayload: JSON.stringify(sent.raw),
          },
        })
      }

      await enqueueWebhook({
        eventType: 'message.sent',
        instanceId: request.instanceId,
        data: {
          gateway_message_id: gatewayMessageId,
          client_message_id: request.clientMessageId ?? null,
          whatsapp_message_id: sent.messageId ?? null,
          recipient: request.recipient,
        },
        metadata: request.metadata ?? {},
      })

      log.info({ whatsappMessageId: sent.messageId }, 'Message sent')
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error)

      // Repeated send failures raise the session-health risk score; an
      // escalation into a warning band is reported so Laravel can pause the
      // number before WhatsApp does, and a move into CRITICAL trips the circuit
      // breaker (halts outbound for a cooldown).
      const health = sessionHealth.recordSendFailure(request.instanceId)
      if (health.escalated) {
        await enqueueWebhook(healthWarningWebhook(request.instanceId, health))

        if (health.band === 'critical') {
          sendCooldowns.set(
            request.instanceId,
            env().SESSION_HEALTH_CRITICAL_COOLDOWN_MINUTES * 60_000,
            `session health critical (score ${health.score}, ${health.reason})`,
          )
        }
      }

      await prisma.gatewayMessage.update({
        where: { id: gatewayMessageId },
        data: { status: 'FAILED', failedAt: new Date(), failureReason: reason.slice(0, 1000) },
      })

      await enqueueWebhook({
        eventType: 'message.failed',
        instanceId: request.instanceId,
        data: {
          gateway_message_id: gatewayMessageId,
          client_message_id: request.clientMessageId ?? null,
          recipient: request.recipient,
          reason,
        },
        metadata: request.metadata ?? {},
      })

      // Deliberately not rethrown: the failure is already durable and has been
      // reported to Laravel. Throwing here would only produce an unhandled
      // rejection, since nothing is awaiting this task.
      log.warn({ err: error }, 'Message send failed')
    }
  })
}

/** Supports Laravel's "did my earlier request actually land?" check. */
export async function findByIdempotencyKey(key: string) {
  return prisma.gatewayMessage.findUnique({ where: { idempotencyKey: key } })
}
