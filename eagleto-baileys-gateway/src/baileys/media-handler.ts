import { createHash, createHmac } from 'node:crypto'
import type { Dirent } from 'node:fs'
import { mkdir, readdir, stat, unlink, writeFile } from 'node:fs/promises'
import { join } from 'node:path'

import type { Logger } from 'pino'

import { env } from '../config/env.js'
import { contextLogger } from '../config/logger.js'
import { secureCompare } from '../security/encryption.js'
import type { InboundMediaDescriptor } from '../types/index.js'
import type { BaileysSocketHandle } from './adapter/types.js'

/**
 * Inbound media: download, vet, store, hand back a descriptor.
 *
 * Two rules shape everything here.
 *
 * First, bytes never travel in an event. `storeInbound` returns an
 * `InboundMediaDescriptor` carrying a signed URL; Laravel fetches the file
 * separately. Inlining a 20 MB video as base64 would bloat every webhook,
 * every retry of that webhook, and every log line that touched it.
 *
 * Second, the MIME check is an allowlist. This file is about to be handed to
 * another system that will store it, index it and quite possibly serve it back
 * to a browser. A blocklist only excludes the attacks known on the day it was
 * written; an allowlist refuses everything nobody has justified. An
 * unrecognised type is rejected, not stored "just in case".
 */

/**
 * Types WhatsApp actually delivers and Laravel is prepared to handle.
 * Anything outside this set is refused — see the note above on allowlists.
 */
const ALLOWED_MIME_TYPES: ReadonlySet<string> = new Set([
  // Images
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',

  // Video
  'video/mp4',
  'video/3gpp',
  'video/quicktime',

  // Audio — voice notes arrive as audio/ogg, forwarded music as the others.
  'audio/ogg',
  'audio/mpeg',
  'audio/mp4',

  // Documents
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'application/vnd.ms-powerpoint',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'text/plain',
])

const MIME_EXTENSIONS: Readonly<Record<string, string>> = {
  'image/jpeg': '.jpg',
  'image/png': '.png',
  'image/gif': '.gif',
  'image/webp': '.webp',
  'video/mp4': '.mp4',
  'video/3gpp': '.3gp',
  'video/quicktime': '.mov',
  'audio/ogg': '.ogg',
  'audio/mpeg': '.mp3',
  'audio/mp4': '.m4a',
  'application/pdf': '.pdf',
  'application/msword': '.doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '.docx',
  'application/vnd.ms-excel': '.xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': '.xlsx',
  'application/vnd.ms-powerpoint': '.ppt',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation': '.pptx',
  'text/plain': '.txt',
}

/**
 * Instance ids come from our own database, but they are interpolated into a
 * filesystem path — so they are validated rather than trusted. A `..` reaching
 * this far would write outside the media root.
 */
const SAFE_INSTANCE_ID = /^[A-Za-z0-9_-]+$/

/** Lowercase hex, 32 bytes — the output width of HMAC-SHA256. */
const SIGNATURE_PATTERN = /^[0-9a-f]{64}$/i

export type MediaRejectionReason = 'too_large' | 'disallowed_mime' | 'invalid_instance_id' | 'empty'

/**
 * Thrown when media is refused rather than stored. Carries a machine-readable
 * reason so the caller can report the right thing to Laravel instead of
 * pattern-matching on an error string.
 */
export class MediaRejectedError extends Error {
  readonly reason: MediaRejectionReason

  constructor(reason: MediaRejectionReason, message: string) {
    super(message)
    this.name = 'MediaRejectedError'
    this.reason = reason
  }
}

export interface StoreInboundInput {
  instanceId: string
  whatsappMessageId: string
  /** The raw Baileys message, passed straight back to `socket.downloadMedia`. */
  message: unknown
  socket: BaileysSocketHandle
  mimeType: string
  fileName?: string
  caption?: string
}

/**
 * A MIME type may arrive with parameters — WhatsApp voice notes are literally
 * `audio/ogg; codecs=opus`. Comparing the raw header against the allowlist
 * would reject every voice note ever sent.
 */
function baseMimeType(mimeType: string): string {
  const separator = mimeType.indexOf(';')

  return (separator === -1 ? mimeType : mimeType.slice(0, separator)).trim().toLowerCase()
}

export function extensionForMime(mime: string): string {
  return MIME_EXTENSIONS[baseMimeType(mime)] ?? '.bin'
}

/**
 * The exact string covered by a media signature.
 *
 * Exported so the signer and the verifier can never drift apart — there is one
 * definition of "what was signed". The expiry is inside the signature, not
 * merely alongside it, so a caller cannot extend a URL's life by editing the
 * query string.
 */
export function mediaSignaturePayload(relativePath: string, expiresAtEpoch: number): string {
  return `${relativePath}.${expiresAtEpoch}`
}

/** HMAC-SHA256 over the signed payload, lowercase hex. */
export function mediaSignature(relativePath: string, expiresAtEpoch: number): string {
  return createHmac('sha256', env().LARAVEL_SIGNING_SECRET)
    .update(mediaSignaturePayload(relativePath, expiresAtEpoch), 'utf8')
    .digest('hex')
}

/**
 * Build the full signed download URL.
 *
 * `MEDIA_PUBLIC_BASE_URL` defaults to an empty string, in which case this
 * yields a root-relative URL — correct when the gateway is served behind the
 * same host as its media route.
 */
export function signMediaUrl(relativePath: string, expiresAtEpoch: number): string {
  const base = env().MEDIA_PUBLIC_BASE_URL.replace(/\/+$/, '')
  const signature = mediaSignature(relativePath, expiresAtEpoch)

  return `${base}/${relativePath}?expires=${expiresAtEpoch}&signature=${signature}`
}

/**
 * Verify a signed media URL.
 *
 * Both halves matter: the signature proves the path was issued by us, and the
 * expiry bounds how long a leaked URL stays useful. A URL that verifies forever
 * is a permanent public link to a customer's file.
 */
export function verifyMediaUrlSignature(
  relativePath: string,
  expiresAtEpoch: number,
  signature: string,
  /** Test seam; defaults to the wall clock. */
  nowMs: number = Date.now(),
): boolean {
  if (!Number.isInteger(expiresAtEpoch) || expiresAtEpoch <= 0) {
    return false
  }

  // Checked before comparing so obvious garbage is never handed to
  // secureCompare, and a malformed value cannot be mistaken for a near miss.
  if (!SIGNATURE_PATTERN.test(signature)) {
    return false
  }

  if (expiresAtEpoch * 1000 < nowMs) {
    return false
  }

  // secureCompare, never `===`: a byte-by-byte early exit leaks how much of a
  // forged signature was correct, which is enough to reconstruct it one byte
  // at a time.
  return secureCompare(mediaSignature(relativePath, expiresAtEpoch), signature.toLowerCase())
}

export class MediaHandler {
  private readonly log: Logger

  constructor(log: Logger = contextLogger({})) {
    this.log = log
  }

  /**
   * Download, vet and store one inbound media file, returning the descriptor
   * that goes into the `message.received` webhook.
   */
  async storeInbound(input: StoreInboundInput): Promise<InboundMediaDescriptor> {
    const { instanceId, whatsappMessageId, message, socket, mimeType } = input

    if (!SAFE_INSTANCE_ID.test(instanceId)) {
      throw new MediaRejectedError(
        'invalid_instance_id',
        `Refusing to store media for instance id "${instanceId}": it is not a safe path segment.`,
      )
    }

    const mime = baseMimeType(mimeType)

    // Checked before the download, not after: refusing a type we would never
    // store anyway costs nothing, while downloading it first spends bandwidth
    // and memory on a file destined for the bin.
    if (!ALLOWED_MIME_TYPES.has(mime)) {
      throw new MediaRejectedError(
        'disallowed_mime',
        `Refusing media of type "${mime}" on message ${whatsappMessageId}: not in the allowed type list.`,
      )
    }

    const e = env()
    const maxBytes = e.MAX_MEDIA_SIZE_MB * 1024 * 1024
    const buffer = await socket.downloadMedia(message, maxBytes)

    if (buffer.length === 0) {
      throw new MediaRejectedError(
        'empty',
        `Media on message ${whatsappMessageId} downloaded as zero bytes.`,
      )
    }

    // The adapter is asked to enforce maxBytes, but the limit is re-checked
    // here because three Baileys implementations sit behind that interface and
    // this is the last point before the bytes reach the disk.
    if (buffer.length > maxBytes) {
      throw new MediaRejectedError(
        'too_large',
        `Media on message ${whatsappMessageId} is ${buffer.length} bytes, over the ${maxBytes} byte limit.`,
      )
    }

    const sha256 = createHash('sha256').update(buffer).digest('hex')
    const extension = extensionForMime(mime)

    /**
     * Content-addressed storage: the on-disk name is the hash, never the
     * sender-supplied `fileName`. The sender controls that string, so letting
     * it reach the filesystem invites traversal and collisions — and hashing
     * means the same forwarded file is stored once per instance.
     *
     * Built with `/` rather than path.join because this half is a URL path;
     * the disk path is joined separately below so Windows separators never
     * leak into a link.
     */
    const relativePath = `${instanceId}/${sha256}${extension}`
    const directory = join(e.MEDIA_STORAGE_PATH, instanceId)

    await mkdir(directory, { recursive: true })
    await writeFile(join(directory, `${sha256}${extension}`), buffer)

    const expiresAtEpoch = Math.floor(Date.now() / 1000) + e.TEMP_MEDIA_RETENTION_MINUTES * 60

    this.log.debug(
      { instanceId, whatsappMessageId, mime, sizeBytes: buffer.length, sha256 },
      'stored inbound media',
    )

    // Note what is absent: no buffer, no base64, no file path. The descriptor
    // is a reference, and stays small enough to sit in an event envelope.
    return {
      mimeType: mime,
      fileName: input.fileName,
      sizeBytes: buffer.length,
      sha256,
      downloadUrl: signMediaUrl(relativePath, expiresAtEpoch),
      expiresAt: new Date(expiresAtEpoch * 1000).toISOString(),
      caption: input.caption,
    }
  }

  /**
   * Delete media past its retention window, returning how many files went.
   *
   * Retention is short by design: this store is a hand-off buffer, not an
   * archive. Laravel has already been told when each URL expires.
   */
  async pruneExpired(): Promise<number> {
    const e = env()
    const root = e.MEDIA_STORAGE_PATH
    const cutoffMs = Date.now() - e.TEMP_MEDIA_RETENTION_MINUTES * 60 * 1000

    const instanceDirectories = await this.readDirectorySafely(root)
    let removed = 0

    for (const entry of instanceDirectories) {
      if (!entry.isDirectory()) {
        continue
      }

      const directory = join(root, entry.name)

      for (const file of await this.readDirectorySafely(directory)) {
        if (!file.isFile()) {
          continue
        }

        const fullPath = join(directory, file.name)

        try {
          const stats = await stat(fullPath)

          if (stats.mtimeMs < cutoffMs) {
            await unlink(fullPath)
            removed += 1
          }
        } catch (error) {
          // A file vanishing between readdir and unlink is expected: another
          // gateway node may share this volume and prune on the same schedule.
          // Losing a race is not a failure, so only genuine errors are logged.
          if (!isMissingEntry(error)) {
            this.log.warn({ err: error, path: fullPath }, 'could not prune media file')
          }
        }
      }
    }

    return removed
  }

  /**
   * `readdir` that treats "not there yet" as "nothing to do".
   *
   * The media root is created lazily on first inbound file, so a prune
   * scheduled before any media has arrived must return 0 rather than crash the
   * job that called it.
   */
  private async readDirectorySafely(path: string): Promise<Dirent[]> {
    try {
      return await readdir(path, { withFileTypes: true })
    } catch (error) {
      if (isMissingEntry(error)) {
        return []
      }

      this.log.warn({ err: error, path }, 'could not read media directory during prune')

      return []
    }
  }
}

function isMissingEntry(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'code' in error &&
    (error as { code?: unknown }).code === 'ENOENT'
  )
}
