import { createHmac } from 'node:crypto'

import { env } from '../config/env.js'
import { secureCompare } from './encryption.js'

/**
 * Request signing for the Laravel -> gateway channel.
 *
 * An API key alone proves only that the caller once held a shared string. It
 * survives being logged by a proxy, copied into a bug report, or replayed off
 * the wire. The signature binds each request to its own method, path, body,
 * timestamp and nonce, so a captured request cannot be edited or resent.
 *
 * This module is deliberately free of Fastify and Prisma so the wire format can
 * be tested — and reimplemented on the PHP side — in isolation.
 *
 * Canonical base (newline separated, in this exact order):
 *
 *   <timestamp>\n<nonce>\n<METHOD>\n<path>\n<rawBody>
 *
 * `path` is the request target INCLUDING the query string. Signing the path
 * alone would let an attacker rewrite `?limit=1` to `?limit=100000` on a
 * captured request without invalidating the signature.
 *
 * `rawBody` is the exact bytes received, never a re-serialised object: JSON
 * key order and whitespace are not stable across a parse/stringify round trip,
 * so re-encoding would break otherwise valid signatures.
 */

/** Lowercase hex, 32 bytes — the output width of HMAC-SHA256. */
const SIGNATURE_PATTERN = /^[0-9a-fA-F]{64}$/

/** Unix seconds. Bounded length so a pathological string cannot reach Number(). */
const TIMESTAMP_PATTERN = /^\d{1,15}$/

/**
 * The nonce is attacker-chosen, and it is a field in a newline-delimited base.
 * Allowing a newline inside it would let a caller shift the remaining fields
 * ("nonce\nGET\n/v1/harmless") so that two different requests produce the same
 * base — which makes a captured signature reusable against a different path.
 * Restricting the charset removes that class entirely.
 */
const NONCE_PATTERN = /^[A-Za-z0-9_-]{8,128}$/

export type SignatureFailureReason =
  | 'invalid_nonce_format'
  | 'invalid_timestamp'
  | 'timestamp_expired'
  | 'timestamp_in_future'
  | 'invalid_signature_format'
  | 'invalid_signature'

export type SignatureVerification = { ok: true } | { ok: false; reason: SignatureFailureReason }

export interface VerifyRequestSignatureInput {
  /** Shared signing secret (LARAVEL_SIGNING_SECRET). */
  secret: string
  timestamp: string
  nonce: string
  method: string
  /** Request target including query string. */
  path: string
  rawBody: string
  signature: string
  /** Defaults to REQUEST_MAX_SKEW_SECONDS. Passed explicitly by tests. */
  maxSkewSeconds?: number
  /** Test seam; defaults to the wall clock. */
  nowMs?: number
}

export function buildSignatureBase(
  timestamp: string,
  nonce: string,
  method: string,
  path: string,
  rawBody: string,
): string {
  // Method is normalised because HTTP verbs are case-insensitive on the wire
  // but a signature over "get" would not match one over "GET".
  return [timestamp, nonce, method.toUpperCase(), path, rawBody].join('\n')
}

export function signRequest(
  secret: string,
  timestamp: string,
  nonce: string,
  method: string,
  path: string,
  rawBody: string,
): string {
  return createHmac('sha256', secret)
    .update(buildSignatureBase(timestamp, nonce, method, path, rawBody), 'utf8')
    .digest('hex')
}

export function verifyRequestSignature(input: VerifyRequestSignatureInput): SignatureVerification {
  const { secret, timestamp, nonce, method, path, rawBody, signature } = input

  if (!NONCE_PATTERN.test(nonce)) {
    return { ok: false, reason: 'invalid_nonce_format' }
  }

  const trimmedTimestamp = timestamp.trim()

  if (!TIMESTAMP_PATTERN.test(trimmedTimestamp)) {
    return { ok: false, reason: 'invalid_timestamp' }
  }

  const maxSkewSeconds = input.maxSkewSeconds ?? env().REQUEST_MAX_SKEW_SECONDS
  const nowSeconds = Math.floor((input.nowMs ?? Date.now()) / 1000)
  const ageSeconds = nowSeconds - Number(trimmedTimestamp)

  if (ageSeconds > maxSkewSeconds) {
    return { ok: false, reason: 'timestamp_expired' }
  }

  /**
   * A future timestamp is rejected as firmly as an expired one, because the
   * replay defence is bounded by this same window: nonce rows are pruned once
   * they age past the skew, so a request stamped an hour ahead would still
   * verify after its nonce had been swept — an indefinitely replayable
   * request. Refusing the future keeps "signature valid" and "nonce still
   * remembered" the same span of time.
   */
  if (ageSeconds < -maxSkewSeconds) {
    return { ok: false, reason: 'timestamp_in_future' }
  }

  // Checked before comparing so a malformed value cannot be mistaken for a
  // near miss, and so secureCompare is never handed obvious garbage.
  if (!SIGNATURE_PATTERN.test(signature)) {
    return { ok: false, reason: 'invalid_signature_format' }
  }

  const expected = signRequest(secret, trimmedTimestamp, nonce, method, path, rawBody)

  // secureCompare, never ===: a byte-by-byte early exit leaks how much of a
  // forged signature was correct, which is enough to reconstruct it one byte
  // at a time. Compared lowercase so hex casing is not treated as a mismatch.
  if (!secureCompare(expected, signature.toLowerCase())) {
    return { ok: false, reason: 'invalid_signature' }
  }

  return { ok: true }
}
