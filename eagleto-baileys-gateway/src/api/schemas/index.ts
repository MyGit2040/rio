import { z } from 'zod'

/**
 * Request validation for the Laravel -> gateway API.
 *
 * Every schema lives here rather than beside its route so the vocabulary stays
 * consistent: one definition of "a recipient", one of "an idempotency key". A
 * second, slightly different recipient rule in one route is how a number that
 * sends fine through /text starts failing through /image.
 *
 * The wire format is snake_case throughout, matching the webhook envelope and
 * Laravel's own conventions. Field names are never abbreviated on the wire.
 */

// ---------------------------------------------------------------------------
// Primitives
// ---------------------------------------------------------------------------

/**
 * A JID (`1234@s.whatsapp.net`, `1234-567@g.us`, `abc@lid`) is accepted as-is:
 * groups, broadcasts and LIDs have no phone number, and rewriting them would
 * silently redirect the message to a different chat. See src/baileys/jid-utils.
 */
const JID_PATTERN = /^[A-Za-z0-9._:-]{1,72}@[A-Za-z0-9.-]{1,64}$/

/** Human phone formatting: digits plus the separators people actually type. */
const PHONE_PATTERN = /^[0-9+\s().-]+$/

/** Ids we mint (cuid) and ids we accept back. Deliberately no `.`, `/` or `..`. */
const OPAQUE_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/

export const recipientSchema = z
  .string()
  .trim()
  .min(1, 'A recipient is required.')
  .max(128, 'A recipient may not exceed 128 characters.')
  .refine(
    (value) =>
      value.includes('@')
        ? JID_PATTERN.test(value)
        : PHONE_PATTERN.test(value) && /\d/.test(value),
    'A recipient must be a phone number (digits, optionally with +, spaces or separators) or a full JID.',
  )

/**
 * Required on every send. This is the entire basis of safe retries: Laravel may
 * resend a request it never saw a response to, and the key is what stops that
 * becoming a second WhatsApp message.
 */
export const idempotencyKeySchema = z
  .string()
  .trim()
  .min(1, 'An idempotency_key is required on every send.')
  .max(255, 'An idempotency_key may not exceed 255 characters.')

export const clientMessageIdSchema = z.string().trim().min(1).max(255)

/**
 * Opaque Laravel bookkeeping, echoed back on every webhook for this message.
 * Passthrough by design — the gateway never interprets these keys, and a schema
 * that enumerated them would have to change every time Laravel added a field.
 */
export const metadataSchema = z.record(z.unknown())

export const instanceIdSchema = z
  .string()
  .trim()
  .regex(OPAQUE_ID_PATTERN, 'An instance id may only contain letters, digits, hyphens and underscores.')

export const instanceParamsSchema = z.object({
  instanceId: instanceIdSchema,
})

export type InstanceParams = z.infer<typeof instanceParamsSchema>

// ---------------------------------------------------------------------------
// Instances
// ---------------------------------------------------------------------------

export const BAILEYS_PACKAGE_VALUES = ['v6', 'v7rc', 'fork'] as const

export const createInstanceSchema = z.object({
  tenant_reference: z.string().trim().min(1).max(128),
  /** Laravel's whatsapp_instances.instance_name — the join key between systems. */
  external_instance_id: z.string().trim().min(1).max(128),
  display_name: z.string().trim().min(1).max(128).optional(),
  phone_number: z.string().trim().min(1).max(32).regex(PHONE_PATTERN).optional(),
  /** Per-instance override of BAILEYS_PACKAGE, for trialling one number on another build. */
  baileys_package: z.enum(BAILEYS_PACKAGE_VALUES).optional(),
  webhook_url: z.string().trim().url().max(2048).optional(),
  enabled: z.boolean().optional(),
})

export type CreateInstanceBody = z.infer<typeof createInstanceSchema>

/**
 * Pairing-code login needs the number in international form. Baileys wants bare
 * digits, so the leading `+` and separators are stripped after validation.
 */
export const pairingCodeSchema = z.object({
  phone_number: z
    .string()
    .trim()
    .min(6, 'A phone number in international format is required to request a pairing code.')
    .max(32)
    .regex(PHONE_PATTERN, 'A phone number may only contain digits and the usual separators.')
    .refine((value) => value.replace(/\D/g, '').length >= 6, 'A phone number must contain at least 6 digits.'),
})

export type PairingCodeBody = z.infer<typeof pairingCodeSchema>

// ---------------------------------------------------------------------------
// Proxy
// ---------------------------------------------------------------------------

export const PROXY_TYPES = ['http', 'https', 'socks5'] as const

export const proxySchema = z.object({
  type: z.enum(PROXY_TYPES),
  host: z.string().trim().min(1).max(255),
  port: z.coerce.number().int().min(1).max(65_535),
  username: z.string().min(1).max(255).optional(),
  password: z.string().min(1).max(255).optional(),
})

export type ProxyBody = z.infer<typeof proxySchema>

// ---------------------------------------------------------------------------
// Sending
// ---------------------------------------------------------------------------

const sendBaseSchema = z.object({
  instance_id: instanceIdSchema,
  recipient: recipientSchema,
  idempotency_key: idempotencyKeySchema,
  client_message_id: clientMessageIdSchema.optional(),
  metadata: metadataSchema.optional(),
  /** WhatsApp id of the message being replied to, when this is a quote. */
  reply_to_message_id: z.string().trim().min(1).max(255).optional(),
})

export type SendBase = z.infer<typeof sendBaseSchema>

export const sendTextSchema = sendBaseSchema.extend({
  // WhatsApp's own ceiling is 65,536; anything near it is a bug in the caller.
  text: z.string().min(1, 'text may not be empty.').max(65_536),
})

export type SendTextBody = z.infer<typeof sendTextSchema>

/**
 * Media fields shared by image/video/audio/document.
 *
 * `url` and `base64` are mutually exclusive rather than "at least one": given
 * both, any choice the gateway made would be a guess about which the caller
 * meant, and the wrong guess sends the wrong file.
 */
const mediaFieldsSchema = z.object({
  url: z
    .string()
    .trim()
    .url()
    .max(2048)
    .refine((value) => /^https?:\/\//i.test(value), 'A media url must be http or https.')
    .optional(),
  base64: z.string().min(1).optional(),
  caption: z.string().max(4096).optional(),
  file_name: z.string().trim().min(1).max(255).optional(),
  mime_type: z.string().trim().min(1).max(255).optional(),
})

function requireExactlyOneSource(
  value: { url?: string | undefined; base64?: string | undefined },
  ctx: z.RefinementCtx,
): void {
  const hasUrl = value.url !== undefined
  const hasBase64 = value.base64 !== undefined

  if (hasUrl === hasBase64) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['url'],
      message: hasUrl
        ? 'Provide either url or base64, not both.'
        : 'A media send requires either url or base64.',
    })
  }
}

const mediaSendSchema = sendBaseSchema.merge(mediaFieldsSchema)

export const sendImageSchema = mediaSendSchema.superRefine(requireExactlyOneSource)
export const sendVideoSchema = mediaSendSchema.superRefine(requireExactlyOneSource)
export const sendAudioSchema = mediaSendSchema.superRefine(requireExactlyOneSource)
export const sendDocumentSchema = mediaSendSchema.superRefine(requireExactlyOneSource)

export type SendMediaBody = z.infer<typeof mediaSendSchema>

export const sendLocationSchema = sendBaseSchema.extend({
  latitude: z.coerce.number().min(-90).max(90),
  longitude: z.coerce.number().min(-180).max(180),
  name: z.string().trim().min(1).max(255).optional(),
  address: z.string().trim().min(1).max(512).optional(),
})

export type SendLocationBody = z.infer<typeof sendLocationSchema>

export const sendContactSchema = sendBaseSchema.extend({
  full_name: z.string().trim().min(1).max(255),
  contact_phone_number: z
    .string()
    .trim()
    .min(1)
    .max(32)
    .regex(PHONE_PATTERN, 'A contact phone number may only contain digits and the usual separators.')
    .refine((value) => /\d/.test(value), 'A contact phone number must contain at least one digit.'),
  organization: z.string().trim().min(1).max(255).optional(),
})

export type SendContactBody = z.infer<typeof sendContactSchema>

/**
 * WhatsApp caps a poll at 12 options. Duplicates are refused because votes are
 * resolved back to option *names*: two identical options are indistinguishable
 * in the aggregate, so Laravel could never tell which one was chosen.
 */
export const sendPollSchema = sendBaseSchema
  .extend({
    question: z.string().trim().min(1, 'A poll question is required.').max(255),
    options: z
      .array(z.string().trim().min(1, 'A poll option may not be empty.').max(100))
      .min(2, 'A poll needs at least 2 options.')
      .max(12, 'WhatsApp allows at most 12 poll options.'),
    selectable_count: z.coerce.number().int().min(1).default(1),
  })
  .superRefine((value, ctx) => {
    if (new Set(value.options).size !== value.options.length) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['options'],
        message: 'Poll options must be unique.',
      })
    }

    if (value.selectable_count > value.options.length) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['selectable_count'],
        message: 'selectable_count cannot exceed the number of options.',
      })
    }
  })

export type SendPollBody = z.infer<typeof sendPollSchema>

export const idempotencyKeyParamsSchema = z.object({
  key: idempotencyKeySchema,
})

export type IdempotencyKeyParams = z.infer<typeof idempotencyKeyParamsSchema>

// ---------------------------------------------------------------------------
// Error rendering
// ---------------------------------------------------------------------------

/**
 * One human-readable line for a failed parse.
 *
 * Field paths are included deliberately: a caller that cannot see *which* field
 * was rejected has to guess, and a 422 that cannot be acted on is only a slower
 * 500. Nothing here echoes the submitted value, which could be message content.
 */
export function describeZodError(error: z.ZodError): string {
  return error.issues
    .map((issue) => {
      const path = issue.path.join('.')

      return path === '' ? issue.message : `${path}: ${issue.message}`
    })
    .join('; ')
}
