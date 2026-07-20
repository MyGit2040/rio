import { createRequire } from 'node:module'

import type { BaileysPackageId } from '../../config/env.js'
import { logger } from '../../config/logger.js'
import type { DisconnectClassification, InstanceState } from '../../types/index.js'

import {
  BAILEYS_PACKAGE_SPECS,
  type BaileysAdapter,
  type BaileysSocketHandle,
  type ConnectionUpdate,
  type CreateSocketOptions,
  type PollVoteResolutionInput,
  type ResolvedPollVote,
  type SentMessageHandle,
} from './types.js'

/**
 * Loader for the switchable Baileys implementations.
 *
 * Verified against the installed packages: @whiskeysockets/baileys 6.7.23 and
 * 7.0.0-rc13 expose the same core surface (makeWASocket, DisconnectReason,
 * initAuthCreds, getAggregateVotesInPollMessage, updateMessageWithPollUpdate,
 * downloadMediaMessage, BufferJSON, proto) and — importantly — an identical
 * DisconnectReason numeric map. One parameterised adapter therefore serves both
 * lines; only the module name differs.
 *
 * The Itsukichan fork is loaded by exactly the same path. It is a fork of the
 * 7.x line so the surface is expected to match, but it is NOT installed by
 * default and has NOT been verified here — `assertSurface` below turns any
 * mismatch into a loud, named failure at load time rather than a confusing
 * crash mid-send.
 */

const require = createRequire(import.meta.url)

/** Exports the gateway genuinely depends on. Missing any of these is fatal. */
const REQUIRED_EXPORTS = [
  'makeWASocket',
  'DisconnectReason',
  'initAuthCreds',
  'BufferJSON',
  'proto',
  'getAggregateVotesInPollMessage',
  'updateMessageWithPollUpdate',
  'downloadMediaMessage',
] as const

type BaileysModule = Record<string, any>

const cache = new Map<BaileysPackageId, BaileysAdapter>()

function assertSurface(mod: BaileysModule, packageId: BaileysPackageId, moduleName: string): void {
  const missing = REQUIRED_EXPORTS.filter((name) => {
    // makeWASocket is the default export in some builds and a named one in others.
    if (name === 'makeWASocket') {
      return typeof mod.makeWASocket !== 'function' && typeof mod.default !== 'function'
    }

    return mod[name] === undefined
  })

  if (missing.length > 0) {
    throw new Error(
      `Baileys package '${packageId}' (${moduleName}) is missing required exports: ${missing.join(', ')}. ` +
        `This build is not compatible with the gateway adapter. ` +
        `Either pick a different BAILEYS_PACKAGE or extend src/baileys/adapter for this build.`,
    )
  }
}

function resolveVersion(moduleName: string): string {
  try {
    const pkg = require(`${moduleName}/package.json`) as { name?: string; version?: string }

    return `${pkg.name ?? moduleName}@${pkg.version ?? 'unknown'}`
  } catch {
    return `${moduleName}@unknown`
  }
}

/**
 * Extract the numeric close code from a Baileys disconnect error.
 * Baileys wraps these in Boom, so the code lives at output.statusCode.
 */
function extractStatusCode(error: unknown): number | undefined {
  const candidate = error as { output?: { statusCode?: unknown }; statusCode?: unknown } | undefined
  const raw = candidate?.output?.statusCode ?? candidate?.statusCode

  return typeof raw === 'number' ? raw : undefined
}

function classify(mod: BaileysModule, error: unknown): DisconnectClassification {
  const reasons = mod.DisconnectReason as Record<string, number>
  const code = extractStatusCode(error)
  const message = (error as { message?: string } | undefined)?.message

  const decide = (
    cls: DisconnectClassification['class'],
    recoverable: boolean,
    nextState: InstanceState,
    reason: string,
  ): DisconnectClassification => ({ class: cls, recoverable, nextState, code, reason })

  switch (code) {
    case reasons.loggedOut:
      // The number was unlinked from the phone. Reconnecting cannot succeed and
      // would just hammer WhatsApp — this needs a fresh QR.
      return decide('logged_out', false, 'LOGGED_OUT', 'Device was logged out of WhatsApp.')

    case reasons.connectionReplaced:
      // Another session took over. Racing it back would fight the user's own
      // device, so ownership is surrendered deliberately.
      return decide('replaced', false, 'REPLACED', 'Connection was replaced by another session.')

    case reasons.forbidden:
      return decide('restricted', false, 'RESTRICTED', 'WhatsApp rejected this account as forbidden.')

    case reasons.badSession:
      return decide('bad_session', false, 'ERROR', 'Session file is invalid; the number must be re-linked.')

    case reasons.multideviceMismatch:
      return decide('auth_required', false, 'ERROR', 'Multi-device mismatch; re-link required.')

    case reasons.connectionClosed:
    case reasons.connectionLost:
    case reasons.timedOut:
      return decide('reconnect', true, 'DISCONNECTED', 'Transient connection loss.')

    case reasons.restartRequired:
      return decide('reconnect', true, 'DISCONNECTED', 'Baileys requested a restart.')

    case reasons.unavailableService:
      return decide('reconnect', true, 'DISCONNECTED', 'WhatsApp service temporarily unavailable.')

    default:
      // An unrecognised code is treated as transient so one unknown blip cannot
      // permanently kill a working number — but it is reported verbatim so the
      // gap shows up in diagnostics instead of hiding.
      return decide(
        'reconnect',
        true,
        'DISCONNECTED',
        `Unclassified disconnect (code=${code ?? 'none'}${message ? `, message=${message}` : ''}).`,
      )
  }
}

function wrapSocket(mod: BaileysModule, sock: any): BaileysSocketHandle {
  return {
    raw: sock,

    onConnectionUpdate(handler: (update: ConnectionUpdate) => void) {
      sock.ev.on('connection.update', handler)
    },
    onCredsUpdate(handler: () => void | Promise<void>) {
      sock.ev.on('creds.update', handler)
    },
    onMessagesUpsert(handler: (payload: unknown) => void | Promise<void>) {
      sock.ev.on('messages.upsert', handler)
    },
    onMessagesUpdate(handler: (payload: unknown) => void | Promise<void>) {
      sock.ev.on('messages.update', handler)
    },
    onMessageReceiptUpdate(handler: (payload: unknown) => void | Promise<void>) {
      sock.ev.on('message-receipt.update', handler)
    },
    onCall(handler: (payload: unknown) => void | Promise<void>) {
      sock.ev.on('call', handler)
    },

    async sendMessage(jid: string, content: unknown, options?: unknown): Promise<SentMessageHandle> {
      const sent = await sock.sendMessage(jid, content, options ?? {})

      return { messageId: sent?.key?.id ?? undefined, raw: sent }
    },

    async requestPairingCode(phoneNumber: string): Promise<string> {
      return sock.requestPairingCode(phoneNumber)
    },

    async logout(): Promise<void> {
      await sock.logout()
    },

    end(error?: Error): void {
      sock.end(error)
    },

    user() {
      return sock.user
    },

    async downloadMedia(message: unknown, maxBytes: number): Promise<Buffer> {
      const buffer: Buffer = await mod.downloadMediaMessage(
        message,
        'buffer',
        {},
        {
          logger: logger(),
          // Media keys expire; without this the gateway cannot fetch anything
          // older than a few minutes.
          reuploadRequest: sock.updateMediaMessage,
        },
      )

      if (buffer.length > maxBytes) {
        throw new Error(
          `Inbound media is ${buffer.length} bytes, above the ${maxBytes}-byte limit; refusing to store it.`,
        )
      }

      return buffer
    },
  }
}

async function build(packageId: BaileysPackageId): Promise<BaileysAdapter> {
  const spec = BAILEYS_PACKAGE_SPECS[packageId]

  let mod: BaileysModule

  try {
    mod = (await import(spec.moduleName)) as BaileysModule
  } catch (error) {
    const hint = spec.optIn
      ? ` This package is opt-in and is not installed by default — run 'npm run use:baileys:fork' after completing the fork audit.`
      : ''

    throw new Error(
      `Could not load Baileys package '${packageId}' (${spec.moduleName}).${hint} ` +
        `Underlying error: ${(error as Error).message}`,
    )
  }

  assertSurface(mod, packageId, spec.moduleName)

  const makeSocket = (mod.makeWASocket ?? mod.default) as (config: unknown) => any
  const version = resolveVersion(spec.moduleName)

  return {
    id: packageId,
    label: spec.label,
    version,

    async createSocket(options: CreateSocketOptions): Promise<BaileysSocketHandle> {
      const socketLogger = logger().child({ instanceId: options.instanceId, baileys: packageId })

      const sock = makeSocket({
        auth: {
          creds: options.auth.creds,
          // The cacheable wrapper cuts repeated Signal key reads, which matters
          // because every send touches the key store.
          keys: mod.makeCacheableSignalKeyStore
            ? mod.makeCacheableSignalKeyStore(options.auth.keys, socketLogger)
            : options.auth.keys,
        },
        logger: socketLogger,
        browser: mod.Browsers?.ubuntu?.(options.browserName ?? 'Chrome') ?? undefined,
        agent: options.agent,
        // Pairing-code logins must not also emit a QR.
        printQRInTerminal: false,
        // The gateway is a sender, not a client: it must not announce presence
        // or drag down full history on connect. Both are load, not stealth.
        markOnlineOnConnect: false,
        syncFullHistory: false,
        generateHighQualityLinkPreview: false,
      })

      return wrapSocket(mod, sock)
    },

    classifyDisconnect(error: unknown): DisconnectClassification {
      return classify(mod, error)
    },

    extractMessages(payload: unknown): unknown[] {
      const messages = (payload as { messages?: unknown } | undefined)?.messages

      return Array.isArray(messages) ? messages : []
    },

    contentType(message: unknown): string | undefined {
      const inner = (message as { message?: unknown } | undefined)?.message

      if (!inner || typeof mod.getContentType !== 'function') {
        return undefined
      }

      return mod.getContentType(inner) as string | undefined
    },

    resolvePollVote(input: PollVoteResolutionInput): ResolvedPollVote | null {
      const creation = input.pollCreation as {
        pollUpdates?: unknown[]
        message?: unknown
        key?: { id?: string }
      }

      if (!creation?.message) {
        return null
      }

      // Fold this update into the retained creation message, then re-aggregate.
      // Baileys accumulates votes on the creation message itself, so the stored
      // payload is both the input and the running total.
      mod.updateMessageWithPollUpdate(creation, input.update)

      const aggregated = mod.getAggregateVotesInPollMessage(
        { message: creation.message, pollUpdates: creation.pollUpdates },
        input.meId,
      ) as Array<{ name: string; voters: string[] }>

      const selectedOptions = aggregated
        .filter((entry) => entry.voters.includes(input.voterJid))
        .map((entry) => entry.name)

      const selectedOptionIndexes = selectedOptions
        .map((name) => input.options.indexOf(name))
        .filter((index) => index >= 0)

      return {
        voterJid: input.voterJid,
        pollMessageId: creation.key?.id ?? '',
        selectedOptionIndexes,
        selectedOptions,
      }
    },

    buildPollContent(question: string, options: string[], selectableCount: number): unknown {
      // Shape verified against PollMessageOptions in both 6.7.23 and 7.0.0-rc13.
      return {
        poll: {
          name: question,
          values: options,
          selectableCount,
        },
      }
    },
  }
}

/** Load (and memoise) the adapter for a package id. */
export async function loadAdapter(packageId: BaileysPackageId): Promise<BaileysAdapter> {
  const existing = cache.get(packageId)

  if (existing) {
    return existing
  }

  const adapter = await build(packageId)
  cache.set(packageId, adapter)

  logger().info(
    { baileysPackage: packageId, version: adapter.version },
    'Loaded Baileys implementation',
  )

  return adapter
}

export function clearAdapterCacheForTesting(): void {
  cache.clear()
}

export * from './types.js'
