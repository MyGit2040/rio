import { createHmac } from 'node:crypto'

import { describe, expect, it } from 'vitest'

import {
  signWebhook,
  signedPayload,
  verifyWebhookSignature,
} from '../../src/webhooks/webhook-signer.js'

/**
 * These vectors are the contract with Laravel's verifier. If a change here
 * requires updating a hardcoded digest, every deployed Laravel instance would
 * start rejecting webhooks — so the constants are the point of the test, not
 * an implementation detail of it.
 */
const SECRET = 'whsec_test_secret'
const TIMESTAMP = '1735689600'
const BODY = '{"event_id":"evt_123"}'
const EXPECTED = '5a504bfcb6360773d0243d6e65e7a3b86c1fb7a5f0ad102617a59a6902c8a0f2'

describe('signWebhook', () => {
  it('produces the known vector for a fixed secret, timestamp and body', () => {
    expect(signWebhook(SECRET, TIMESTAMP, BODY)).toBe(EXPECTED)
  })

  it('signs exactly `timestamp + "." + rawBody`', () => {
    const independent = createHmac('sha256', SECRET).update(`${TIMESTAMP}.${BODY}`, 'utf8').digest('hex')

    expect(signWebhook(SECRET, TIMESTAMP, BODY)).toBe(independent)
    expect(signedPayload(TIMESTAMP, BODY)).toBe(`${TIMESTAMP}.${BODY}`)
  })

  it('is deterministic', () => {
    expect(signWebhook(SECRET, TIMESTAMP, BODY)).toBe(signWebhook(SECRET, TIMESTAMP, BODY))
  })

  it('returns lowercase hex of a full SHA-256 digest', () => {
    expect(signWebhook(SECRET, TIMESTAMP, BODY)).toMatch(/^[0-9a-f]{64}$/)
  })

  it('changes when the secret changes', () => {
    expect(signWebhook('another_secret', TIMESTAMP, BODY)).not.toBe(EXPECTED)
  })

  it('includes the timestamp in the signed material', () => {
    // The whole point of signing the timestamp: a captured body cannot be
    // replayed under a fresh timestamp without invalidating the signature.
    expect(signWebhook(SECRET, '1735689601', BODY)).not.toBe(EXPECTED)
  })

  it('signs an empty body without throwing', () => {
    expect(signWebhook(SECRET, TIMESTAMP, '')).toBe(
      '59b7a0a2445b876af0c38beb1244d7778bbd211046bebb74e7230da7e5004c22',
    )
  })
})

describe('verifyWebhookSignature', () => {
  it('accepts a signature it produced', () => {
    expect(verifyWebhookSignature(SECRET, TIMESTAMP, BODY, signWebhook(SECRET, TIMESTAMP, BODY))).toBe(true)
  })

  it('rejects a tampered body', () => {
    const signature = signWebhook(SECRET, TIMESTAMP, BODY)

    expect(verifyWebhookSignature(SECRET, TIMESTAMP, '{"event_id":"evt_666"}', signature)).toBe(false)
  })

  it('rejects a single flipped byte in the body', () => {
    const signature = signWebhook(SECRET, TIMESTAMP, BODY)

    expect(verifyWebhookSignature(SECRET, TIMESTAMP, `${BODY} `, signature)).toBe(false)
  })

  it('rejects a replayed signature under a different timestamp', () => {
    const signature = signWebhook(SECRET, TIMESTAMP, BODY)

    expect(verifyWebhookSignature(SECRET, '1735689601', BODY, signature)).toBe(false)
  })

  it('rejects a signature made with a different secret', () => {
    const forged = signWebhook('attacker_secret', TIMESTAMP, BODY)

    expect(verifyWebhookSignature(SECRET, TIMESTAMP, BODY, forged)).toBe(false)
  })

  it('rejects an empty, truncated or malformed signature', () => {
    const signature = signWebhook(SECRET, TIMESTAMP, BODY)

    expect(verifyWebhookSignature(SECRET, TIMESTAMP, BODY, '')).toBe(false)
    expect(verifyWebhookSignature(SECRET, TIMESTAMP, BODY, signature.slice(0, 32))).toBe(false)
    expect(verifyWebhookSignature(SECRET, TIMESTAMP, BODY, 'not-a-signature')).toBe(false)
    expect(verifyWebhookSignature(SECRET, TIMESTAMP, BODY, signature.toUpperCase())).toBe(false)
  })
})
