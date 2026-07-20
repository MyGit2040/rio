import { afterEach, describe, expect, it } from 'vitest'

import { setEnvForTesting } from '../../src/config/env.js'
import {
  buildSignatureBase,
  signRequest,
  verifyRequestSignature,
} from '../../src/security/request-signature.js'
import { testEnv, TEST_SIGNING_SECRET } from '../helpers/env-fixture.js'

const SECRET = TEST_SIGNING_SECRET
const NONCE = 'b4f1c2d3e4a5b6c7'
const NOW_MS = 1_780_000_000_000
const NOW_SECONDS = Math.floor(NOW_MS / 1000)
const BODY = '{"to":"971500000000","text":"hello"}'
const PATH = '/v1/instances/abc/messages?dryRun=false'

function validInput(overrides: Record<string, unknown> = {}) {
  const timestamp = String(NOW_SECONDS)

  return {
    secret: SECRET,
    timestamp,
    nonce: NONCE,
    method: 'POST',
    path: PATH,
    rawBody: BODY,
    signature: signRequest(SECRET, timestamp, NONCE, 'POST', PATH, BODY),
    maxSkewSeconds: 300,
    nowMs: NOW_MS,
    ...overrides,
  }
}

afterEach(() => {
  setEnvForTesting(null)
})

describe('buildSignatureBase', () => {
  it('joins the five fields with newlines in a fixed order', () => {
    expect(buildSignatureBase('1780000000', NONCE, 'POST', '/v1/x', '{"a":1}')).toBe(
      `1780000000\n${NONCE}\nPOST\n/v1/x\n{"a":1}`,
    )
  })

  it('normalises the method so verb casing cannot break a signature', () => {
    expect(buildSignatureBase('1', NONCE, 'post', '/v1/x', '')).toBe(
      buildSignatureBase('1', NONCE, 'POST', '/v1/x', ''),
    )
  })

  it('includes the query string, so query tampering changes the base', () => {
    expect(buildSignatureBase('1', NONCE, 'GET', '/v1/x?limit=1', '')).not.toBe(
      buildSignatureBase('1', NONCE, 'GET', '/v1/x?limit=1000', ''),
    )
  })
})

describe('signRequest', () => {
  it('produces 64 lowercase hex characters', () => {
    expect(signRequest(SECRET, '1780000000', NONCE, 'POST', '/v1/x', '')).toMatch(/^[0-9a-f]{64}$/)
  })

  it('is deterministic for identical input', () => {
    const a = signRequest(SECRET, '1780000000', NONCE, 'POST', PATH, BODY)
    const b = signRequest(SECRET, '1780000000', NONCE, 'POST', PATH, BODY)

    expect(a).toBe(b)
  })

  it('changes when the secret changes', () => {
    expect(signRequest(SECRET, '1780000000', NONCE, 'POST', PATH, BODY)).not.toBe(
      signRequest('another-secret', '1780000000', NONCE, 'POST', PATH, BODY),
    )
  })
})

describe('verifyRequestSignature', () => {
  it('accepts a correctly signed request', () => {
    expect(verifyRequestSignature(validInput())).toEqual({ ok: true })
  })

  it('accepts an uppercase hex signature', () => {
    const input = validInput()

    expect(
      verifyRequestSignature({ ...input, signature: input.signature.toUpperCase() }),
    ).toEqual({ ok: true })
  })

  it('rejects a tampered body', () => {
    const result = verifyRequestSignature(
      validInput({ rawBody: '{"to":"971500000000","text":"goodbye"}' }),
    )

    expect(result).toEqual({ ok: false, reason: 'invalid_signature' })
  })

  it('rejects a tampered path', () => {
    const result = verifyRequestSignature(validInput({ path: '/v1/instances/OTHER/messages' }))

    expect(result).toEqual({ ok: false, reason: 'invalid_signature' })
  })

  it('rejects a tampered query string on an otherwise identical path', () => {
    const result = verifyRequestSignature(
      validInput({ path: '/v1/instances/abc/messages?dryRun=true' }),
    )

    expect(result).toEqual({ ok: false, reason: 'invalid_signature' })
  })

  it('rejects a tampered method', () => {
    expect(verifyRequestSignature(validInput({ method: 'DELETE' }))).toEqual({
      ok: false,
      reason: 'invalid_signature',
    })
  })

  it('rejects a signature made with the wrong secret', () => {
    const timestamp = String(NOW_SECONDS)

    const result = verifyRequestSignature(
      validInput({
        signature: signRequest('wrong-secret', timestamp, NONCE, 'POST', PATH, BODY),
      }),
    )

    expect(result).toEqual({ ok: false, reason: 'invalid_signature' })
  })

  it('rejects a request replayed with a different nonce (the nonce is signed)', () => {
    expect(verifyRequestSignature(validInput({ nonce: 'ffffffffffffffff' }))).toEqual({
      ok: false,
      reason: 'invalid_signature',
    })
  })

  it('rejects an expired timestamp', () => {
    const timestamp = String(NOW_SECONDS - 301)

    const result = verifyRequestSignature(
      validInput({
        timestamp,
        signature: signRequest(SECRET, timestamp, NONCE, 'POST', PATH, BODY),
      }),
    )

    expect(result).toEqual({ ok: false, reason: 'timestamp_expired' })
  })

  it('rejects a future timestamp just as firmly as an expired one', () => {
    const timestamp = String(NOW_SECONDS + 301)

    const result = verifyRequestSignature(
      validInput({
        timestamp,
        signature: signRequest(SECRET, timestamp, NONCE, 'POST', PATH, BODY),
      }),
    )

    expect(result).toEqual({ ok: false, reason: 'timestamp_in_future' })
  })

  it('accepts a timestamp at both edges of the skew window', () => {
    for (const offset of [-300, 300]) {
      const timestamp = String(NOW_SECONDS - offset)

      expect(
        verifyRequestSignature(
          validInput({
            timestamp,
            signature: signRequest(SECRET, timestamp, NONCE, 'POST', PATH, BODY),
          }),
        ),
      ).toEqual({ ok: true })
    }
  })

  it('rejects a non-numeric timestamp', () => {
    expect(verifyRequestSignature(validInput({ timestamp: 'not-a-number' }))).toEqual({
      ok: false,
      reason: 'invalid_timestamp',
    })
  })

  it('rejects a nonce containing the field delimiter, which could shift the base', () => {
    expect(verifyRequestSignature(validInput({ nonce: 'abcdefgh\nGET\n/v1/other' }))).toEqual({
      ok: false,
      reason: 'invalid_nonce_format',
    })
  })

  it('rejects a nonce that is too short to be unguessable', () => {
    expect(verifyRequestSignature(validInput({ nonce: 'abc' }))).toEqual({
      ok: false,
      reason: 'invalid_nonce_format',
    })
  })

  it('rejects a malformed signature before comparing it', () => {
    expect(verifyRequestSignature(validInput({ signature: 'nope' }))).toEqual({
      ok: false,
      reason: 'invalid_signature_format',
    })
  })

  it('falls back to REQUEST_MAX_SKEW_SECONDS when no skew is supplied', () => {
    setEnvForTesting(testEnv({ REQUEST_MAX_SKEW_SECONDS: '10' }))

    const timestamp = String(NOW_SECONDS - 60)
    const input = validInput({
      timestamp,
      signature: signRequest(SECRET, timestamp, NONCE, 'POST', PATH, BODY),
    })

    delete (input as { maxSkewSeconds?: number }).maxSkewSeconds

    expect(verifyRequestSignature(input)).toEqual({ ok: false, reason: 'timestamp_expired' })
  })
})
