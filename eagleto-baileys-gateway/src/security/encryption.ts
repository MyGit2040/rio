import {
  createCipheriv,
  createDecipheriv,
  randomBytes,
  timingSafeEqual,
} from 'node:crypto'

import { env } from '../config/env.js'

/**
 * Envelope encryption for data at rest (WhatsApp auth state, proxy passwords,
 * webhook secrets).
 *
 * Each record gets its own random data encryption key (DEK); the DEK is then
 * wrapped with the master key. Compromising one ciphertext therefore does not
 * hand over a key that decrypts every other row, and rotating the master key
 * means rewrapping DEKs rather than re-encrypting every payload.
 *
 * AES-256-GCM throughout, so tampering is detected on read rather than
 * producing silent garbage that would corrupt a session.
 */

const ALGORITHM = 'aes-256-gcm'
const VERSION = 'v1'
const IV_LENGTH = 12 // 96-bit nonce, the GCM standard
const KEY_LENGTH = 32

export class DecryptionError extends Error {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options)
    this.name = 'DecryptionError'
  }
}

function masterKey(): Buffer {
  const key = Buffer.from(env().MASTER_ENCRYPTION_KEY, 'hex')

  if (key.length !== KEY_LENGTH) {
    throw new Error('MASTER_ENCRYPTION_KEY must decode to exactly 32 bytes.')
  }

  return key
}

function seal(plaintext: Buffer, key: Buffer): { iv: Buffer; tag: Buffer; ciphertext: Buffer } {
  const iv = randomBytes(IV_LENGTH)
  const cipher = createCipheriv(ALGORITHM, key, iv)
  const ciphertext = Buffer.concat([cipher.update(plaintext), cipher.final()])

  return { iv, tag: cipher.getAuthTag(), ciphertext }
}

function open(ciphertext: Buffer, key: Buffer, iv: Buffer, tag: Buffer): Buffer {
  const decipher = createDecipheriv(ALGORITHM, key, iv)
  decipher.setAuthTag(tag)

  return Buffer.concat([decipher.update(ciphertext), decipher.final()])
}

/**
 * Encrypt a UTF-8 string into a self-describing envelope:
 *
 *   v1.<wrappedDek>.<dekIv>.<dekTag>.<iv>.<tag>.<ciphertext>   (all base64url)
 *
 * The version prefix exists so a future algorithm change can be rolled out
 * without guessing at the format of existing rows.
 */
export function encrypt(plaintext: string): string {
  const dek = randomBytes(KEY_LENGTH)
  const payload = seal(Buffer.from(plaintext, 'utf8'), dek)
  const wrapped = seal(dek, masterKey())

  // Wipe the plaintext DEK from memory as soon as it is wrapped.
  dek.fill(0)

  return [
    VERSION,
    wrapped.ciphertext.toString('base64url'),
    wrapped.iv.toString('base64url'),
    wrapped.tag.toString('base64url'),
    payload.iv.toString('base64url'),
    payload.tag.toString('base64url'),
    payload.ciphertext.toString('base64url'),
  ].join('.')
}

export function decrypt(envelope: string): string {
  const parts = envelope.split('.')

  if (parts.length !== 7 || parts[0] !== VERSION) {
    throw new DecryptionError(
      `Unrecognised ciphertext envelope. Expected 7 ${VERSION} segments, got ${parts.length}.`,
    )
  }

  const [, wrappedDek, dekIv, dekTag, iv, tag, ciphertext] = parts as [
    string, string, string, string, string, string, string,
  ]

  let dek: Buffer | null = null

  try {
    dek = open(
      Buffer.from(wrappedDek, 'base64url'),
      masterKey(),
      Buffer.from(dekIv, 'base64url'),
      Buffer.from(dekTag, 'base64url'),
    )

    const plaintext = open(
      Buffer.from(ciphertext, 'base64url'),
      dek,
      Buffer.from(iv, 'base64url'),
      Buffer.from(tag, 'base64url'),
    )

    return plaintext.toString('utf8')
  } catch (error) {
    // A failure here almost always means the master key changed or the row was
    // tampered with. Say so plainly — the usual cause is a redeployed
    // MASTER_ENCRYPTION_KEY, and the consequence is that sessions need
    // re-linking, which the operator needs to understand immediately.
    throw new DecryptionError(
      'Could not decrypt stored data. The MASTER_ENCRYPTION_KEY may have changed since it was written.',
      { cause: error },
    )
  } finally {
    dek?.fill(0)
  }
}

/** Convenience wrappers for the JSON blobs the auth store persists. */
export function encryptJson(value: unknown): string {
  return encrypt(JSON.stringify(value))
}

export function decryptJson<T>(envelope: string): T {
  return JSON.parse(decrypt(envelope)) as T
}

export function encryptOptional(value: string | null | undefined): string | null {
  return value === null || value === undefined || value === '' ? null : encrypt(value)
}

export function decryptOptional(envelope: string | null | undefined): string | null {
  return envelope === null || envelope === undefined || envelope === '' ? null : decrypt(envelope)
}

/**
 * Constant-time string comparison for secrets (API keys, signatures).
 * Length is compared first because timingSafeEqual throws on a length
 * mismatch — but the early return only leaks length, never content.
 */
export function secureCompare(a: string, b: string): boolean {
  const left = Buffer.from(a, 'utf8')
  const right = Buffer.from(b, 'utf8')

  if (left.length !== right.length) {
    return false
  }

  return timingSafeEqual(left, right)
}
