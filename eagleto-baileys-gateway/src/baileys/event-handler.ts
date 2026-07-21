import { env } from '../config/env.js'
import { contextLogger } from '../config/logger.js'
import { prisma } from '../database/client.js'
import type { InboundMessageKind, MessageStatus, NormalisedInboundMessage } from '../types/index.js'
import { gaussianBetween } from '../util/timing.js'
import { enqueueWebhook } from '../webhooks/webhook-dispatcher.js'

import type { BaileysAdapter, BaileysSocketHandle, MessageKey } from './adapter/types.js'
import { isBroadcastJid, isGroupJid, normaliseJid, phoneFromJid } from './jid-utils.js'
import { MediaHandler } from './media-handler.js'
import { handlePollUpdate } from './poll-handler.js'

/**
 * Mark an inbound message read after a humanised delay, without blocking the
 * upsert handler.
 *
 * A read receipt that lands the instant a message arrives is a machine tell — a
 * person takes a beat to notice and open a chat. The delay is a Gaussian draw in
 * [READ_RECEIPT_MIN_MS, READ_RECEIPT_MAX_MS], so most reads happen a few seconds
 * later with rare quicker/slower ones. Best-effort: a socket that has since
 * closed simply rejects, and that is swallowed — a missed blue tick is
 * immaterial, and it must never disturb inbound processing.
 */
function scheduleDelayedRead(socket: BaileysSocketHandle, key: MessageKey, log: ReturnType<typeof contextLogger>): void {
  if (!env().READ_RECEIPT_SIMULATION_ENABLED) {
    return
  }

  const delayMs = gaussianBetween(env().READ_RECEIPT_MIN_MS, env().READ_RECEIPT_MAX_MS)

  // Unref so a pending read never holds the process open at shutdown.
  setTimeout(() => {
    void socket.readMessages([key]).catch((error: unknown) => {
      log.debug({ err: error, whatsappMessageId: key.id }, 'Delayed read receipt could not be sent')
    })
  }, delayMs).unref()
}

/**
 * Translates raw Baileys events into gateway webhooks.
 *
 * Everything here is normalisation: Laravel must never have to know which
 * Baileys line produced an event, nor parse a protobuf. Anything that cannot be
 * normalised confidently is logged and dropped rather than forwarded in a shape
 * Laravel would have to guess at.
 */

const mediaHandler = new MediaHandler()

/**
 * Baileys' WAMessageStatus enum. Numeric rather than named because the wire
 * values are what arrive in `messages.update`.
 */
const STATUS_BY_CODE: Record<number, MessageStatus> = {
  0: 'FAILED', // ERROR
  2: 'SERVER_ACK', // SERVER_ACK — WhatsApp accepted it, the device has not confirmed
  3: 'DELIVERED', // DELIVERY_ACK
  4: 'READ',
  5: 'PLAYED',
}

/** Which Baileys content type maps to which inbound kind. */
function inboundKind(contentType: string | undefined): InboundMessageKind {
  switch (contentType) {
    case 'conversation':
    case 'extendedTextMessage':
      return 'text'
    case 'imageMessage':
      return 'image'
    case 'videoMessage':
      return 'video'
    case 'audioMessage':
      return 'audio'
    case 'documentMessage':
    case 'documentWithCaptionMessage':
      return 'document'
    case 'contactMessage':
    case 'contactsArrayMessage':
      return 'contact'
    case 'locationMessage':
    case 'liveLocationMessage':
      return 'location'
    case 'reactionMessage':
      return 'reaction'
    case 'pollUpdateMessage':
      return 'poll_update'
    case 'buttonsResponseMessage':
    case 'templateButtonReplyMessage':
      return 'button_reply'
    case 'listResponseMessage':
      return 'list_reply'
    default:
      return 'unknown'
  }
}

function extractText(message: Record<string, any> | undefined): string | undefined {
  if (!message) {
    return undefined
  }

  return (
    message.conversation ??
    message.extendedTextMessage?.text ??
    message.imageMessage?.caption ??
    message.videoMessage?.caption ??
    message.documentMessage?.caption ??
    message.buttonsResponseMessage?.selectedDisplayText ??
    message.templateButtonReplyMessage?.selectedDisplayText ??
    message.listResponseMessage?.title ??
    undefined
  )
}

function mediaNode(message: Record<string, any> | undefined): Record<string, any> | undefined {
  if (!message) {
    return undefined
  }

  return (
    message.imageMessage ??
    message.videoMessage ??
    message.audioMessage ??
    message.documentMessage ??
    message.documentWithCaptionMessage?.message?.documentMessage ??
    undefined
  )
}

export function registerEventHandlers(
  instanceId: string,
  socket: BaileysSocketHandle,
  adapter: BaileysAdapter,
): void {
  const log = contextLogger({ instanceId })

  socket.onMessagesUpsert(async (payload) => {
    try {
      await handleUpsert(instanceId, socket, adapter, payload)
    } catch (error) {
      log.error({ err: error }, 'Failed handling inbound messages')
    }
  })

  socket.onMessagesUpdate(async (payload) => {
    try {
      await handleUpdates(instanceId, socket, adapter, payload)
    } catch (error) {
      log.error({ err: error }, 'Failed handling message updates')
    }
  })

  socket.onMessageReceiptUpdate(async (payload) => {
    try {
      await handleReceipts(instanceId, payload)
    } catch (error) {
      log.error({ err: error }, 'Failed handling receipts')
    }
  })

  socket.onCall(async (payload) => {
    try {
      await handleCalls(instanceId, payload)
    } catch (error) {
      log.error({ err: error }, 'Failed handling call event')
    }
  })
}

async function handleUpsert(
  instanceId: string,
  socket: BaileysSocketHandle,
  adapter: BaileysAdapter,
  payload: unknown,
): Promise<void> {
  const log = contextLogger({ instanceId })

  for (const raw of adapter.extractMessages(payload)) {
    const message = raw as Record<string, any>
    const key = message.key as { id?: string; remoteJid?: string; fromMe?: boolean } | undefined

    if (!key?.id || !key.remoteJid) {
      continue
    }

    // Groups and status broadcasts are out of scope for this product; Laravel
    // has no model for them and forwarding them would produce contacts that
    // are not real people.
    if (isGroupJid(key.remoteJid) || isBroadcastJid(key.remoteJid)) {
      continue
    }

    const contentType = adapter.contentType(message)
    const kind = inboundKind(contentType)

    // Our own outbound echo. Status tracking is handled by receipts, so
    // re-reporting it as an inbound message would create phantom replies.
    if (key.fromMe) {
      continue
    }

    const normalised: NormalisedInboundMessage = {
      whatsappMessageId: key.id,
      chatJid: normaliseJid(key.remoteJid),
      senderJid: normaliseJid((message.participant as string | undefined) ?? key.remoteJid),
      fromMe: false,
      pushName: message.pushName as string | undefined,
      kind,
      text: extractText(message.message as Record<string, any> | undefined),
      timestamp: new Date(Number(message.messageTimestamp ?? Date.now() / 1000) * 1000).toISOString(),
      quotedMessageId:
        (message.message?.extendedTextMessage?.contextInfo?.stanzaId as string | undefined) ?? undefined,
    }

    const node = mediaNode(message.message as Record<string, any> | undefined)

    if (node && ['image', 'video', 'audio', 'document'].includes(kind)) {
      try {
        normalised.media = await mediaHandler.storeInbound({
          instanceId,
          whatsappMessageId: key.id,
          message,
          socket,
          mimeType: (node.mimetype as string) ?? 'application/octet-stream',
          fileName: node.fileName as string | undefined,
          caption: node.caption as string | undefined,
        })
      } catch (error) {
        // An unusable attachment must not lose the message itself — the text
        // and sender are still worth delivering.
        log.warn({ err: error, whatsappMessageId: key.id }, 'Could not store inbound media')
      }
    }

    await enqueueWebhook({
      eventType: 'message.received',
      instanceId,
      data: {
        whatsapp_message_id: normalised.whatsappMessageId,
        chat_jid: normalised.chatJid,
        sender_jid: normalised.senderJid,
        phone: phoneFromJid(normalised.senderJid),
        push_name: normalised.pushName ?? null,
        kind: normalised.kind,
        text: normalised.text ?? null,
        quoted_message_id: normalised.quotedMessageId ?? null,
        media: normalised.media ?? null,
        timestamp: normalised.timestamp,
      },
    })

    // Blue-tick the message a few seconds later (opt-in), so read receipts don't
    // appear at machine speed. Non-blocking.
    scheduleDelayedRead(
      socket,
      { remoteJid: key.remoteJid, id: key.id, participant: message.participant as string | undefined, fromMe: false },
      log,
    )
  }
}

async function handleUpdates(
  instanceId: string,
  socket: BaileysSocketHandle,
  adapter: BaileysAdapter,
  payload: unknown,
): Promise<void> {
  const updates = Array.isArray(payload) ? payload : []

  for (const entry of updates) {
    const item = entry as {
      key?: { id?: string; remoteJid?: string; fromMe?: boolean; participant?: string }
      update?: { status?: number; pollUpdates?: unknown[] }
    }

    const messageId = item.key?.id

    if (!messageId) {
      continue
    }

    // Poll votes ride in on messages.update against the poll's own message id.
    const pollUpdates = item.update?.pollUpdates

    if (Array.isArray(pollUpdates) && pollUpdates.length > 0) {
      for (const update of pollUpdates) {
        const voter =
          ((update as { pollUpdateMessageKey?: { participant?: string; remoteJid?: string } })
            .pollUpdateMessageKey?.participant ??
            (update as { pollUpdateMessageKey?: { remoteJid?: string } }).pollUpdateMessageKey?.remoteJid) ??
          item.key?.participant ??
          item.key?.remoteJid

        if (!voter) {
          continue
        }

        await handlePollUpdate({
          instanceId,
          adapter,
          pollMessageId: messageId,
          voterJid: voter,
          update,
          meId: socket.user()?.id,
        })
      }

      continue
    }

    const code = item.update?.status

    if (typeof code === 'number') {
      await applyStatus(instanceId, messageId, code)
    }
  }
}

async function handleReceipts(instanceId: string, payload: unknown): Promise<void> {
  const receipts = Array.isArray(payload) ? payload : []

  for (const entry of receipts) {
    const item = entry as {
      key?: { id?: string }
      receipt?: { readTimestamp?: number; playedTimestamp?: number; receiptTimestamp?: number }
    }

    const messageId = item.key?.id

    if (!messageId) {
      continue
    }

    // Most specific wins: played implies read, read implies delivered.
    const status: MessageStatus = item.receipt?.playedTimestamp
      ? 'PLAYED'
      : item.receipt?.readTimestamp
        ? 'READ'
        : 'DELIVERED'

    await applyStatusName(instanceId, messageId, status)
  }
}

async function applyStatus(instanceId: string, whatsappMessageId: string, code: number): Promise<void> {
  const status = STATUS_BY_CODE[code]

  if (!status) {
    return
  }

  await applyStatusName(instanceId, whatsappMessageId, status)
}

/** Status ranking — a later event must never demote an earlier one. */
const RANK: Record<MessageStatus, number> = {
  ACCEPTED: 0,
  SENT: 1,
  SERVER_ACK: 2,
  DELIVERED: 3,
  READ: 4,
  PLAYED: 5,
  FAILED: 6,
}

async function applyStatusName(
  instanceId: string,
  whatsappMessageId: string,
  status: MessageStatus,
): Promise<void> {
  const record = await prisma.gatewayMessage.findFirst({
    where: { instanceId, whatsappMessageId },
  })

  if (!record) {
    // A message this gateway did not send (or sent before restart without an
    // id). Nothing to attribute it to.
    return
  }

  // WhatsApp can deliver receipts out of order; without this guard a late
  // DELIVERED would overwrite an already-recorded READ.
  if (RANK[status] <= RANK[record.status as MessageStatus] && status !== 'FAILED') {
    return
  }

  const now = new Date()
  const timestamps: Record<string, Date> = {}

  if (status === 'SERVER_ACK') timestamps.serverAckAt = now
  if (status === 'DELIVERED') timestamps.deliveredAt = now
  if (status === 'READ' || status === 'PLAYED') timestamps.readAt = now
  if (status === 'FAILED') timestamps.failedAt = now

  await prisma.gatewayMessage.update({
    where: { id: record.id },
    data: { status, ...timestamps },
  })

  await enqueueWebhook({
    eventType:
      status === 'SERVER_ACK'
        ? 'message.server_ack'
        : status === 'DELIVERED'
          ? 'message.delivered'
          : status === 'READ'
            ? 'message.read'
            : status === 'PLAYED'
              ? 'message.played'
              : 'message.failed',
    instanceId,
    data: {
      gateway_message_id: record.id,
      client_message_id: record.clientMessageId,
      whatsapp_message_id: whatsappMessageId,
      recipient: record.recipient,
      status,
    },
    metadata: (record.metadata ?? {}) as never,
  })
}

async function handleCalls(instanceId: string, payload: unknown): Promise<void> {
  const calls = Array.isArray(payload) ? payload : []

  for (const entry of calls) {
    const call = entry as { from?: string; id?: string; status?: string }

    if (!call.from) {
      continue
    }

    // Reported only. The gateway never places calls — automated call-ringing is
    // explicitly out of scope for this product.
    await enqueueWebhook({
      eventType: 'call.received',
      instanceId,
      data: {
        call_id: call.id ?? null,
        from_jid: normaliseJid(call.from),
        phone: phoneFromJid(call.from),
        status: call.status ?? null,
      },
    })
  }
}

/** Exposed for the media retention job. */
export async function pruneMedia(): Promise<number> {
  return mediaHandler.pruneExpired()
}

export const MEDIA_RETENTION_MINUTES = (): number => env().TEMP_MEDIA_RETENTION_MINUTES
