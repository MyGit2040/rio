import type { FastifyInstance, FastifyReply } from 'fastify'
import type { z } from 'zod'

import {
  DuplicatePayloadError,
  InstanceNotSendableError,
  acceptSend,
  findByIdempotencyKey,
  type SendRequest,
} from '../../baileys/message-sender.js'
import { env } from '../../config/env.js'
import type { MessageMetadata, OutgoingMessageKind, SendAcceptance } from '../../types/index.js'
import {
  describeZodError,
  idempotencyKeyParamsSchema,
  sendAudioSchema,
  sendContactSchema,
  sendDocumentSchema,
  sendImageSchema,
  sendLocationSchema,
  sendTextSchema,
  sendVideoSchema,
  type SendBase,
  type SendMediaBody,
} from '../schemas/index.js'

/**
 * Outbound message endpoints.
 *
 * Every handler does the same three things: validate, build the
 * provider-agnostic content object, hand it to `acceptSend`. The route never
 * touches a socket — transmission happens on the instance's serial queue and the
 * outcome arrives at Laravel as a webhook, which is why these return 202 and not
 * 200. A 200 here would be a claim about WhatsApp that the gateway is in no
 * position to make yet.
 */

export const SEND_ERROR_CODES = {
  invalidBody: 'invalid_body',
  duplicatePayload: 'idempotency_key_reused',
  notSendable: 'instance_not_sendable',
  mediaTooLarge: 'media_too_large',
  invalidBase64: 'invalid_base64',
  notFound: 'message_not_found',
  invalidParams: 'invalid_params',
} as const

export function fail(reply: FastifyReply, status: number, code: string, message: string): FastifyReply {
  return reply.status(status).send({ error: { code, message } })
}

/**
 * Shared failure mapping for anything that goes through `acceptSend`, so the
 * message routes and the poll route can never disagree about what a reused
 * idempotency key means.
 *
 * Both refusals are 409, and both are deliberate:
 *
 *  - A key reused with a *different* payload is refused rather than silently
 *    serving the original. It is almost always a caller bug, and quietly
 *    returning the first message would hide it until someone noticed the wrong
 *    text had gone out. (A key reused with the SAME payload is not an error at
 *    all — it returns the original acceptance with status "duplicate".)
 *
 *  - An instance that cannot send is 409, never a redirect to another number.
 *    The gateway owns transport; which WhatsApp account a message belongs to is
 *    Laravel's decision, and rerouting would send a customer a message from a
 *    number they have never seen — on a number Laravel may have paused for a
 *    reason the gateway cannot know.
 */
export function sendErrorResponse(reply: FastifyReply, error: unknown): FastifyReply | null {
  if (error instanceof DuplicatePayloadError) {
    return fail(reply, 409, SEND_ERROR_CODES.duplicatePayload, error.message)
  }

  if (error instanceof InstanceNotSendableError) {
    return fail(reply, 409, SEND_ERROR_CODES.notSendable, error.message)
  }

  // Not ours to translate — let the error handler log it as a 500.
  return null
}

function acceptanceBody(acceptance: SendAcceptance): Record<string, unknown> {
  return {
    success: true,
    status: acceptance.status,
    gateway_message_id: acceptance.gatewayMessageId,
    client_message_id: acceptance.clientMessageId ?? null,
    instance_id: acceptance.instanceId,
  }
}

/** The fields every send shares, mapped from the wire format onto SendRequest. */
export function baseSendRequest(
  body: SendBase,
  kind: OutgoingMessageKind,
  content: Record<string, unknown>,
): SendRequest {
  return {
    instanceId: body.instance_id,
    recipient: body.recipient,
    kind,
    idempotencyKey: body.idempotency_key,
    clientMessageId: body.client_message_id,
    metadata: body.metadata as MessageMetadata | undefined,
    replyToMessageId: body.reply_to_message_id,
    content,
  }
}

// ---------------------------------------------------------------------------
// Media
// ---------------------------------------------------------------------------

class MediaSourceError extends Error {
  constructor(
    readonly code: string,
    message: string,
  ) {
    super(message)
    this.name = 'MediaSourceError'
  }
}

/** `data:image/png;base64,AAAA...` — browsers and Laravel both produce these. */
const DATA_URL_PREFIX = /^data:[^;,]*;base64,/i
const BASE64_PATTERN = /^[A-Za-z0-9+/]+={0,2}$/

/**
 * Turn `url` or `base64` into the value Baileys expects for a media field.
 *
 * A URL is handed over as `{ url }` and streamed by Baileys — that is the path
 * to prefer for anything large, because base64 has to be buffered whole, in
 * memory, before the request body is even parsed. The process-wide body limit
 * (see app.ts) is the real ceiling on inline media; the check here exists so an
 * oversized payload is refused with a reason rather than a bare 413.
 */
function mediaSource(body: SendMediaBody): { url: string } | Buffer {
  if (body.url !== undefined) {
    return { url: body.url }
  }

  const raw = (body.base64 ?? '').replace(DATA_URL_PREFIX, '').replace(/\s+/g, '')

  if (raw === '' || !BASE64_PATTERN.test(raw)) {
    throw new MediaSourceError(SEND_ERROR_CODES.invalidBase64, 'base64 is not valid base64 data.')
  }

  const buffer = Buffer.from(raw, 'base64')

  if (buffer.length === 0) {
    throw new MediaSourceError(SEND_ERROR_CODES.invalidBase64, 'base64 decoded to zero bytes.')
  }

  const maxBytes = env().MAX_MEDIA_SIZE_MB * 1024 * 1024

  if (buffer.length > maxBytes) {
    throw new MediaSourceError(
      SEND_ERROR_CODES.mediaTooLarge,
      `Inline media is ${buffer.length} bytes, above the ${maxBytes}-byte limit. Send large files by url instead.`,
    )
  }

  return buffer
}

/**
 * WhatsApp needs a mime type for documents and audio to render them correctly;
 * for image and video it infers one. An explicit `mime_type` always wins.
 */
function mediaContent(kind: 'image' | 'video' | 'audio' | 'document', body: SendMediaBody): Record<string, unknown> {
  const source = mediaSource(body)

  const content: Record<string, unknown> = { [kind]: source }

  if (body.caption !== undefined) {
    content.caption = body.caption
  }

  if (body.mime_type !== undefined) {
    content.mimetype = body.mime_type
  }

  if (kind === 'document') {
    // Without a fileName WhatsApp shows a document as an untitled blob, and the
    // recipient has no idea what they have been sent.
    content.fileName = body.file_name ?? 'document'
  } else if (body.file_name !== undefined) {
    content.fileName = body.file_name
  }

  return content
}

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

/** One send handler, parameterised by schema and content builder. */
async function handleSend<S extends z.ZodTypeAny>(
  reply: FastifyReply,
  schema: S,
  rawBody: unknown,
  kind: OutgoingMessageKind,
  buildContent: (body: z.infer<S>) => Record<string, unknown>,
): Promise<FastifyReply> {
  const parsed = schema.safeParse(rawBody)

  if (!parsed.success) {
    return fail(reply, 422, SEND_ERROR_CODES.invalidBody, describeZodError(parsed.error))
  }

  const body = parsed.data as z.infer<S> & SendBase

  let content: Record<string, unknown>

  try {
    content = buildContent(body)
  } catch (error) {
    if (error instanceof MediaSourceError) {
      // 413 for size, 422 for malformed input: the caller's remedy differs.
      const status = error.code === SEND_ERROR_CODES.mediaTooLarge ? 413 : 422

      return fail(reply, status, error.code, error.message)
    }

    throw error
  }

  try {
    const acceptance = await acceptSend(baseSendRequest(body, kind, content))

    return reply.status(202).send(acceptanceBody(acceptance))
  } catch (error) {
    const mapped = sendErrorResponse(reply, error)

    if (mapped) {
      return mapped
    }

    throw error
  }
}

export async function messageRoutes(app: FastifyInstance): Promise<void> {
  app.post('/v1/messages/text', async (request, reply) =>
    handleSend(reply, sendTextSchema, request.body, 'text', (body) => ({ text: body.text })),
  )

  app.post('/v1/messages/image', async (request, reply) =>
    handleSend(reply, sendImageSchema, request.body, 'image', (body) => mediaContent('image', body)),
  )

  app.post('/v1/messages/video', async (request, reply) =>
    handleSend(reply, sendVideoSchema, request.body, 'video', (body) => mediaContent('video', body)),
  )

  app.post('/v1/messages/audio', async (request, reply) =>
    handleSend(reply, sendAudioSchema, request.body, 'audio', (body) => mediaContent('audio', body)),
  )

  app.post('/v1/messages/document', async (request, reply) =>
    handleSend(reply, sendDocumentSchema, request.body, 'document', (body) => mediaContent('document', body)),
  )

  app.post('/v1/messages/location', async (request, reply) =>
    handleSend(reply, sendLocationSchema, request.body, 'location', (body) => ({
      location: {
        degreesLatitude: body.latitude,
        degreesLongitude: body.longitude,
        ...(body.name === undefined ? {} : { name: body.name }),
        ...(body.address === undefined ? {} : { address: body.address }),
      },
    })),
  )

  app.post('/v1/messages/contact', async (request, reply) =>
    handleSend(reply, sendContactSchema, request.body, 'contact', (body) => {
      const digits = body.contact_phone_number.replace(/\D/g, '')

      // A vCard is the only contact format WhatsApp accepts. `waid` is what
      // makes the shared card tappable as a WhatsApp contact rather than a
      // plain phone number.
      const lines = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        `FN:${body.full_name}`,
        ...(body.organization === undefined ? [] : [`ORG:${body.organization}`]),
        `TEL;type=CELL;type=VOICE;waid=${digits}:${body.contact_phone_number}`,
        'END:VCARD',
      ]

      return {
        contacts: {
          displayName: body.full_name,
          contacts: [{ vcard: lines.join('\n') }],
        },
      }
    }),
  )

  /**
   * The answer to "did my earlier request actually land?".
   *
   * This is what Laravel calls after an HTTP timeout, instead of resending
   * blindly: the idempotency key it already holds resolves to the message
   * record, so a slow response never has to become a second WhatsApp message.
   */
  app.get('/v1/messages/by-idempotency-key/:key', async (request, reply) => {
    const params = idempotencyKeyParamsSchema.safeParse(request.params)

    if (!params.success) {
      return fail(reply, 400, SEND_ERROR_CODES.invalidParams, describeZodError(params.error))
    }

    const message = await findByIdempotencyKey(params.data.key)

    if (!message) {
      return fail(
        reply,
        404,
        SEND_ERROR_CODES.notFound,
        `No message was accepted under idempotency key '${params.data.key}'. It is safe to send it.`,
      )
    }

    return reply.status(200).send({
      gateway_message_id: message.id,
      instance_id: message.instanceId,
      idempotency_key: message.idempotencyKey,
      client_message_id: message.clientMessageId,
      recipient: message.recipient,
      kind: message.kind,
      status: message.status,
      whatsapp_message_id: message.whatsappMessageId,
      accepted_at: message.acceptedAt.toISOString(),
      sent_at: message.sentAt?.toISOString() ?? null,
      server_ack_at: message.serverAckAt?.toISOString() ?? null,
      delivered_at: message.deliveredAt?.toISOString() ?? null,
      read_at: message.readAt?.toISOString() ?? null,
      failed_at: message.failedAt?.toISOString() ?? null,
      failure_reason: message.failureReason,
      metadata: message.metadata ?? {},
    })
  })
}
