import type { FastifyInstance, FastifyReply, FastifyRequest } from 'fastify'

import { env } from '../../config/env.js'
import { secureCompare } from '../../security/encryption.js'
import type { NonceStore } from '../../security/nonce-store.js'
import { verifyRequestSignature, type SignatureFailureReason } from '../../security/request-signature.js'
import { requestLogger } from './request-context.js'

/**
 * Authentication for the Laravel -> gateway API.
 *
 * Four things must hold before any handler runs: the caller presents the right
 * API key, the request is signed with the shared secret, the timestamp is
 * inside the accepted skew, and the nonce has never been seen. Optionally the
 * source IP must be on the allow-list.
 *
 * Only the two health probes are exempt. Diagnostics are explicitly NOT
 * exempt: they describe instance state, error text and socket internals, which
 * is exactly the reconnaissance an unauthenticated caller should never get.
 */

declare module 'fastify' {
  interface FastifyRequest {
    /** Exact received bytes, needed because the signature covers the body. */
    rawBody?: string
    /** Set when the body was not valid JSON; reported only after auth passes. */
    bodyParseFailed?: boolean
  }
}

export const HEADER_KEY = 'x-eagleto-key'
export const HEADER_TIMESTAMP = 'x-eagleto-timestamp'
export const HEADER_NONCE = 'x-eagleto-nonce'
export const HEADER_SIGNATURE = 'x-eagleto-signature'

/**
 * Liveness and readiness only. These are unauthenticated because the
 * orchestrator that probes them has no credentials, and because a probe that
 * can fail on an auth misconfiguration would restart a perfectly healthy
 * container.
 */
export const AUTH_EXEMPT_PATHS: readonly string[] = ['/health/live', '/health/ready']

export interface ApiAuthDependencies {
  nonceStore: NonceStore
}

interface ErrorBody {
  error: { code: string; message: string }
}

/**
 * Credential failures deliberately share one opaque code. Telling a caller
 * "the key was right but the signature was wrong" confirms a leaked key is
 * still live; the precise reason goes to the log, where the operator is.
 * Structural failures (missing headers, clock skew, blocked IP) do get a
 * specific code — they are integration mistakes, not secrets, and an
 * integrator cannot fix a clock they are not told about.
 */
const CODE_UNAUTHORIZED = 'unauthorized'
const CODE_MISSING_CREDENTIALS = 'missing_credentials'
const CODE_STALE_TIMESTAMP = 'stale_timestamp'
const CODE_IP_NOT_ALLOWED = 'ip_not_allowed'
const CODE_NONCE_STORE_UNAVAILABLE = 'nonce_store_unavailable'
const CODE_INVALID_JSON = 'invalid_json'

const MESSAGE_UNAUTHORIZED = 'Request authentication failed.'

/** Path without the query string — used for exemption and for logging. */
function pathOf(url: string): string {
  const queryAt = url.indexOf('?')

  return queryAt === -1 ? url : url.slice(0, queryAt)
}

function isExempt(url: string): boolean {
  // Exact match only. A traversal such as /health/live/../v1/send does not
  // match and therefore stays authenticated.
  return AUTH_EXEMPT_PATHS.includes(pathOf(url))
}

/**
 * A repeated header arrives as an array. Rather than pick one, reject: which
 * copy a proxy forwards is not guaranteed, so accepting either would let a
 * request be signed against one value and validated against another.
 */
function singleHeader(request: FastifyRequest, name: string): string | null {
  const value = request.headers[name]

  return typeof value === 'string' && value.length > 0 ? value : null
}

/** IPv4-mapped IPv6 (::ffff:10.0.0.4) is the same host as 10.0.0.4. */
function normaliseIp(ip: string): string {
  const trimmed = ip.trim()

  return trimmed.toLowerCase().startsWith('::ffff:') ? trimmed.slice(7) : trimmed
}

function timestampFailure(reason: SignatureFailureReason): boolean {
  return (
    reason === 'invalid_timestamp' ||
    reason === 'timestamp_expired' ||
    reason === 'timestamp_in_future'
  )
}

export async function registerApiAuth(
  app: FastifyInstance,
  deps: ApiAuthDependencies,
): Promise<void> {
  /**
   * The signature covers the bytes as sent, so the raw string has to be kept
   * before parsing. Replacing the default JSON parser is the only hook that
   * sees them.
   *
   * A body that is not valid JSON does NOT fail here: parsing runs before the
   * auth hook, so failing at this point would answer an unauthenticated caller
   * with a body-parsing verdict. The failure is recorded and reported after
   * authentication instead.
   */
  app.addContentTypeParser(
    'application/json',
    { parseAs: 'string' },
    (request: FastifyRequest, body: string | Buffer, done) => {
      const raw = typeof body === 'string' ? body : body.toString('utf8')
      request.rawBody = raw

      if (raw.length === 0) {
        done(null, null)

        return
      }

      try {
        done(null, JSON.parse(raw))
      } catch {
        request.bodyParseFailed = true
        done(null, null)
      }
    },
  )

  /**
   * The IP gate runs on onRequest — before the body is read — so a request
   * from a blocked address never gets to spend memory on its payload.
   */
  app.addHook('onRequest', async (request, reply) => {
    if (isExempt(request.url)) {
      return
    }

    const allowed = env().API_ALLOWED_IPS

    if (allowed.length === 0) {
      return
    }

    const source = normaliseIp(request.ip)

    if (!allowed.some((entry) => normaliseIp(entry) === source)) {
      return reject(request, reply, 403, CODE_IP_NOT_ALLOWED, 'Source address is not allowed.', {
        reason: 'ip_not_allowed',
        sourceIp: source,
      })
    }
  })

  /**
   * Signature verification needs the parsed-and-preserved raw body, which only
   * exists from preHandler onwards.
   */
  app.addHook('preHandler', async (request, reply) => {
    if (isExempt(request.url)) {
      return
    }

    const key = singleHeader(request, HEADER_KEY)
    const timestamp = singleHeader(request, HEADER_TIMESTAMP)
    const nonce = singleHeader(request, HEADER_NONCE)
    const signature = singleHeader(request, HEADER_SIGNATURE)

    if (key === null || timestamp === null || nonce === null || signature === null) {
      return reject(
        request,
        reply,
        401,
        CODE_MISSING_CREDENTIALS,
        `Requests must carry ${HEADER_KEY}, ${HEADER_TIMESTAMP}, ${HEADER_NONCE} and ${HEADER_SIGNATURE}.`,
        { reason: 'missing_headers' },
      )
    }

    const config = env()

    if (!secureCompare(key, config.LARAVEL_API_KEY)) {
      return reject(request, reply, 401, CODE_UNAUTHORIZED, MESSAGE_UNAUTHORIZED, {
        reason: 'unknown_api_key',
      })
    }

    const verification = verifyRequestSignature({
      secret: config.LARAVEL_SIGNING_SECRET,
      timestamp,
      nonce,
      method: request.method,
      // Full target including query string: the query is signed too.
      path: request.url,
      rawBody: request.rawBody ?? '',
      signature,
      maxSkewSeconds: config.REQUEST_MAX_SKEW_SECONDS,
    })

    if (!verification.ok) {
      const stale = timestampFailure(verification.reason)

      return reject(
        request,
        reply,
        401,
        stale ? CODE_STALE_TIMESTAMP : CODE_UNAUTHORIZED,
        stale
          ? `Request timestamp is outside the accepted ${config.REQUEST_MAX_SKEW_SECONDS}s window. Check the caller's clock.`
          : MESSAGE_UNAUTHORIZED,
        { reason: verification.reason },
      )
    }

    /**
     * The nonce is spent only after the signature proves the caller holds the
     * secret. Consuming earlier would let anyone burn nonces — and fill the
     * table — with unsigned junk.
     */
    let fresh: boolean

    try {
      fresh = await deps.nonceStore.consume(nonce)
    } catch (error) {
      // An unreachable store is an outage, not an authentication verdict.
      // Logged at error because production log level is error; a warning here
      // would be invisible exactly when it matters.
      requestLogger(request).error(
        { requestId: request.requestId, err: error },
        'Nonce store unavailable; rejecting request',
      )

      return reply.status(503).send({
        error: {
          code: CODE_NONCE_STORE_UNAVAILABLE,
          message: 'Replay protection is temporarily unavailable. Retry shortly.',
        },
      } satisfies ErrorBody)
    }

    if (!fresh) {
      return reject(request, reply, 401, CODE_UNAUTHORIZED, MESSAGE_UNAUTHORIZED, {
        reason: 'replayed_nonce',
      })
    }

    // Deferred from the content-type parser so that only an authenticated
    // caller learns anything about how its body was read.
    if (request.bodyParseFailed === true) {
      return reply.status(400).send({
        error: { code: CODE_INVALID_JSON, message: 'Request body is not valid JSON.' },
      } satisfies ErrorBody)
    }
  })
}

/**
 * Logs the precise reason and answers with the opaque one.
 *
 * Never logs the key, the signature or the body — a log sink is a copy of
 * every secret that reaches it, and the redaction in logger.ts is a safety
 * net, not a licence to pass secrets in.
 */
function reject(
  request: FastifyRequest,
  reply: FastifyReply,
  status: number,
  code: string,
  message: string,
  context: { reason: string; sourceIp?: string },
): FastifyReply {
  requestLogger(request).warn(
    {
      requestId: request.requestId,
      reason: context.reason,
      method: request.method,
      // Path only: a query string can carry caller-supplied values.
      path: pathOf(request.url),
      ...(context.sourceIp === undefined ? {} : { sourceIp: context.sourceIp }),
      status,
    },
    'API request rejected',
  )

  return reply.status(status).send({ error: { code, message } } satisfies ErrorBody)
}
