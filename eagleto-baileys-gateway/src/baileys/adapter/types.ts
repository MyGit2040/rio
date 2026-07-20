import type { BaileysPackageId } from '../../config/env.js'
import type { DisconnectClassification } from '../../types/index.js'

/**
 * The façade the rest of the gateway programs against.
 *
 * Nothing outside src/baileys/adapter may import a Baileys package directly.
 * That rule is what makes three implementations switchable: the differences
 * between the 6.x line, the 7.x release candidate and the Itsukichan fork are
 * absorbed here, in one file per package, instead of leaking into the socket
 * manager, sender and event handler.
 *
 * The types below are deliberately loose at the Baileys boundary (`unknown`
 * rather than imported Baileys types) because three packages cannot satisfy one
 * strict compile-time type. Every value crossing back into gateway code is
 * narrowed to a type we own.
 */

export interface BaileysPackageSpec {
  id: BaileysPackageId
  /** The npm alias in package.json — see the aliases in `dependencies`. */
  moduleName: string
  label: string
  /** True for packages that are not installed by default. */
  optIn: boolean
}

export const BAILEYS_PACKAGE_SPECS: Record<BaileysPackageId, BaileysPackageSpec> = {
  v6: {
    id: 'v6',
    moduleName: 'baileys-v6',
    label: '@whiskeysockets/baileys@6.7.23 (stable)',
    optIn: false,
  },
  v7rc: {
    id: 'v7rc',
    moduleName: 'baileys-v7rc',
    label: '@whiskeysockets/baileys@7.0.0-rc13 (release candidate)',
    optIn: false,
  },
  fork: {
    id: 'fork',
    moduleName: 'baileys-fork',
    label: '@itsukichan/baileys@7.3.2 (fork, opt-in)',
    // Not in default dependencies: its preinstall script would run at install
    // time, before anyone had chosen to trust it. Install deliberately with
    // `npm run use:baileys:fork` after the fork audit.
    optIn: true,
  },
}

/** Auth state handed to Baileys, in the shape every supported line expects. */
export interface AuthStateBridge {
  creds: unknown
  keys: {
    get(type: string, ids: string[]): Promise<Record<string, unknown>>
    set(data: Record<string, Record<string, unknown> | null | undefined>): Promise<void>
  }
}

export interface CreateSocketOptions {
  instanceId: string
  auth: AuthStateBridge
  /** Proxy agent, already constructed by the caller. */
  agent?: unknown
  /** Marks the socket as a QR-less pairing-code flow. */
  usePairingCode?: boolean
  browserName?: string
}

/**
 * The subset of socket behaviour the gateway uses. Anything richer stays
 * behind the adapter so an upgrade cannot silently change semantics upstream.
 */
export interface BaileysSocketHandle {
  raw: unknown

  onConnectionUpdate(handler: (update: ConnectionUpdate) => void): void
  onCredsUpdate(handler: () => void | Promise<void>): void
  onMessagesUpsert(handler: (payload: unknown) => void | Promise<void>): void
  onMessagesUpdate(handler: (payload: unknown) => void | Promise<void>): void
  onMessageReceiptUpdate(handler: (payload: unknown) => void | Promise<void>): void
  onCall(handler: (payload: unknown) => void | Promise<void>): void

  sendMessage(jid: string, content: unknown, options?: unknown): Promise<SentMessageHandle>
  requestPairingCode(phoneNumber: string): Promise<string>
  logout(): Promise<void>
  end(error?: Error): void

  /** The connected account's JID, once known. */
  user(): { id: string; name?: string } | undefined

  /**
   * Download inbound media to a buffer, refusing anything over `maxBytes`.
   *
   * This lives on the socket rather than the adapter because Baileys needs the
   * live connection to re-request expired media keys; a detached helper would
   * fail on exactly the older messages most likely to need a re-upload.
   */
  downloadMedia(message: unknown, maxBytes: number): Promise<Buffer>
}

export interface SentMessageHandle {
  /** WhatsApp's message id, when the implementation returns one. */
  messageId: string | undefined
  raw: unknown
}

export interface ConnectionUpdate {
  connection?: 'close' | 'connecting' | 'open'
  qr?: string
  isNewLogin?: boolean
  receivedPendingNotifications?: boolean
  lastDisconnect?: {
    error?: unknown
    date?: Date
  }
}

/**
 * One implementation per supported Baileys package.
 */
export interface BaileysAdapter {
  id: BaileysPackageId
  label: string
  /** Resolved from the package's own package.json at load time, not assumed. */
  version: string

  createSocket(options: CreateSocketOptions): Promise<BaileysSocketHandle>

  /**
   * Turn a socket-close error into a decision. Implementations map their own
   * DisconnectReason enum onto the gateway's vocabulary, because the numeric
   * codes are not guaranteed stable across lines.
   */
  classifyDisconnect(error: unknown): DisconnectClassification

  /** Normalise an upsert payload into plain message records. */
  extractMessages(payload: unknown): unknown[]

  /** Best-effort content-type label for a raw message. */
  contentType(message: unknown): string | undefined

  /**
   * Fold a poll update into the stored creation message and return the
   * resolved selection.
   *
   * WhatsApp delivers poll votes as encrypted aggregates that only make sense
   * against the original poll message, which is why the creation payload has
   * to be retained rather than reconstructed from the question text.
   */
  resolvePollVote(input: PollVoteResolutionInput): ResolvedPollVote | null

  /** Build the provider-specific content object for a poll send. */
  buildPollContent(question: string, options: string[], selectableCount: number): unknown
}

export interface PollVoteResolutionInput {
  /** The raw poll-update entry as delivered by Baileys `messages.update`. */
  update: unknown
  /** The stored Baileys creation message for the original poll. */
  pollCreation: unknown
  /** Option names in their original order, for index resolution. */
  options: string[]
  /** The connected account's JID, used to exclude our own vote. */
  meId?: string | undefined
  voterJid: string
}

export interface ResolvedPollVote {
  voterJid: string
  pollMessageId: string
  selectedOptionIndexes: number[]
  selectedOptions: string[]
}
