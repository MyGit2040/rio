import { createHmac } from 'node:crypto'

import { secureCompare } from '../security/encryption.js'

/**
 * Webhook request signing.
 *
 * Laravel authenticates every event by recomputing this HMAC. The signed
 * material is `timestamp + "." + rawBody` — the timestamp is inside the
 * signature, not merely alongside it, so a captured request cannot be replayed
 * later with a fresh timestamp: changing it invalidates the signature.
 *
 * Pure functions, no I/O, so both sides of the contract can be tested without a
 * network or a database.
 */

/** The separator between timestamp and body. Must match Laravel's verifier. */
const SEPARATOR = '.'

/**
 * The exact string covered by the signature.
 *
 * Exported so a caller can never accidentally re-derive it differently from the
 * verifier — there is one definition of "what was signed".
 */
export function signedPayload(timestamp: string, rawBody: string): string {
  return `${timestamp}${SEPARATOR}${rawBody}`
}

/** HMAC-SHA256, lowercase hex. */
export function signWebhook(secret: string, timestamp: string, rawBody: string): string {
  return createHmac('sha256', secret).update(signedPayload(timestamp, rawBody), 'utf8').digest('hex')
}

/**
 * Constant-time verification.
 *
 * A naive `===` on a signature leaks, byte by byte, how much of a guess was
 * correct — enough to forge one over many attempts. secureCompare compares in
 * time independent of content.
 */
export function verifyWebhookSignature(
  secret: string,
  timestamp: string,
  rawBody: string,
  provided: string,
): boolean {
  if (provided === '') {
    return false
  }

  return secureCompare(signWebhook(secret, timestamp, rawBody), provided)
}
