/**
 * Shared domain types.
 *
 * These are the gateway's own vocabulary. Nothing here imports from Baileys —
 * that boundary is owned by src/baileys/adapter, so a Baileys upgrade or a
 * package switch cannot ripple through the codebase.
 */

// ---------------------------------------------------------------------------
// Instance lifecycle
// ---------------------------------------------------------------------------

export const INSTANCE_STATES = [
  'CREATED',
  'STARTING',
  'QR_REQUIRED',
  'PAIRING_CODE_REQUIRED',
  'PAIRING',
  'AUTHENTICATED',
  'SYNCING',
  'READY',
  'DISCONNECTED',
  'RECONNECT_WAIT',
  'PAUSED',
  'LOGGED_OUT',
  'REPLACED',
  'RESTRICTED',
  'ERROR',
  'STOPPED',
] as const

export type InstanceState = (typeof INSTANCE_STATES)[number]

/**
 * States a socket can leave on its own. Anything else is terminal until an
 * operator (or Laravel) acts — which is what stops a banned or logged-out
 * number from reconnecting in a loop.
 */
export const RECOVERABLE_STATES: readonly InstanceState[] = [
  'DISCONNECTED',
  'RECONNECT_WAIT',
  'STARTING',
  'SYNCING',
  'AUTHENTICATED',
]

export const TERMINAL_STATES: readonly InstanceState[] = [
  'LOGGED_OUT',
  'REPLACED',
  'RESTRICTED',
  'STOPPED',
]

// ---------------------------------------------------------------------------
// Disconnect classification
// ---------------------------------------------------------------------------

/**
 * Why a socket closed, normalised away from Baileys' numeric codes.
 *
 * `reconnect` is the only classification that may trigger an automatic retry.
 * Everything else is a decision for a human or for Laravel, because retrying a
 * logout or a ban is both futile and conspicuous.
 */
export type DisconnectClass =
  | 'reconnect'
  | 'logged_out'
  | 'replaced'
  | 'restricted'
  | 'bad_session'
  | 'auth_required'
  | 'fatal'

export interface DisconnectClassification {
  class: DisconnectClass
  /** Whether the reconnect manager may schedule another attempt. */
  recoverable: boolean
  /** State the instance should move to as a result. */
  nextState: InstanceState
  code?: number | undefined
  reason?: string | undefined
}

// ---------------------------------------------------------------------------
// Sending
// ---------------------------------------------------------------------------

export type OutgoingMessageKind =
  | 'text'
  | 'image'
  | 'video'
  | 'audio'
  | 'document'
  | 'location'
  | 'contact'
  | 'poll'

export type MessageStatus =
  | 'ACCEPTED'
  | 'SENT'
  | 'SERVER_ACK'
  | 'DELIVERED'
  | 'READ'
  | 'PLAYED'
  | 'FAILED'

export interface SendAcceptance {
  gatewayMessageId: string
  clientMessageId?: string | undefined
  status: 'accepted' | 'duplicate'
  instanceId: string
}

/** Arbitrary Laravel-owned context, echoed back on every related webhook. */
export interface MessageMetadata {
  tenant_id?: number | string
  campaign_id?: number | string
  contact_id?: number | string
  [key: string]: unknown
}

// ---------------------------------------------------------------------------
// Webhook events
// ---------------------------------------------------------------------------

export const WEBHOOK_EVENT_TYPES = [
  'instance.created',
  'instance.qr',
  'instance.pairing_code',
  'instance.authenticated',
  'instance.syncing',
  'instance.ready',
  'instance.disconnected',
  'instance.reconnect_wait',
  'instance.logged_out',
  'instance.replaced',
  'instance.restricted',
  'instance.error',

  'message.accepted',
  'message.sent',
  'message.server_ack',
  'message.delivered',
  'message.read',
  'message.played',
  'message.failed',
  'message.received',
  'message.deleted',
  'message.updated',

  'poll.created',
  'poll.vote_received',
  'poll.vote_changed',
  'poll.vote_removed',

  'call.received',
  'call.rejected',

  'gateway.health_warning',
] as const

export type WebhookEventType = (typeof WEBHOOK_EVENT_TYPES)[number]

export interface WebhookEnvelope {
  event_id: string
  event_type: WebhookEventType
  event_version: string
  occurred_at: string
  instance_id: string | null
  data: Record<string, unknown>
  metadata: MessageMetadata
}

// ---------------------------------------------------------------------------
// Inbound messages
// ---------------------------------------------------------------------------

export type InboundMessageKind =
  | 'text'
  | 'image'
  | 'video'
  | 'audio'
  | 'voice'
  | 'document'
  | 'contact'
  | 'location'
  | 'reaction'
  | 'poll_update'
  | 'button_reply'
  | 'list_reply'
  | 'unknown'

export interface NormalisedInboundMessage {
  whatsappMessageId: string
  chatJid: string
  senderJid: string
  fromMe: boolean
  pushName?: string | undefined
  kind: InboundMessageKind
  text?: string | undefined
  timestamp: string
  quotedMessageId?: string | undefined
  media?: InboundMediaDescriptor | undefined
  raw?: unknown
}

/**
 * Media is never inlined into a webhook. Laravel receives a descriptor and
 * fetches the bytes separately, which keeps event delivery small and lets the
 * gateway enforce size and MIME limits before anything is stored.
 */
export interface InboundMediaDescriptor {
  mimeType: string
  fileName?: string | undefined
  sizeBytes: number
  sha256: string
  downloadUrl: string
  expiresAt: string
  caption?: string | undefined
}

// ---------------------------------------------------------------------------
// Health
// ---------------------------------------------------------------------------

export type HealthLevel = 'ok' | 'warning' | 'critical'

export interface HealthCheck {
  name: string
  level: HealthLevel
  detail?: string
  value?: number | string
}

export interface HealthReport {
  level: HealthLevel
  nodeId: string
  checks: HealthCheck[]
  checkedAt: string
}
