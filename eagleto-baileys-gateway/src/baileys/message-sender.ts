import { createHash } from 'node:crypto'

import { contextLogger } from '../config/logger.js'
import { prisma } from '../database/client.js'
import { findInstance } from '../instances/resolve.js'
import { serialQueues } from '../instances/serial-queue.js'
import type { MessageMetadata, OutgoingMessageKind, SendAcceptance } from '../types/index.js'
import { enqueueWebhook } from '../webhooks/webhook-dispatcher.js'

import { toJid } from './jid-utils.js'
import { socketManager } from './socket-manager.js'

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

      const sent = await socket.sendMessage(
        toJid(request.recipient),
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
