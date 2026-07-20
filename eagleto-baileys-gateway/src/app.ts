import { randomUUID } from 'node:crypto'

import rateLimit from '@fastify/rate-limit'
import Fastify, {
  type FastifyBaseLogger,
  type FastifyError,
  type FastifyInstance,
  type FastifyRequest,
} from 'fastify'

import { AUTH_EXEMPT_PATHS, registerApiAuth } from './api/middleware/api-auth.js'
import { registerRequestContext, requestLogger } from './api/middleware/request-context.js'
import { diagnosticsRoutes } from './api/routes/diagnostics.routes.js'
import { healthRoutes } from './api/routes/health.routes.js'
import { instanceRoutes } from './api/routes/instances.routes.js'
import { mediaRoutes } from './api/routes/media.routes.js'
import { messageRoutes } from './api/routes/messages.routes.js'
import { pollRoutes } from './api/routes/polls.routes.js'
import { webhookRoutes } from './api/routes/webhooks.routes.js'
import { env } from './config/env.js'
import { logger } from './config/logger.js'
import { PrismaNonceStore } from './security/nonce-store.js'

/**
 * HTTP surface assembly.
 *
 * Split from server.ts so the app can be built in a test without binding a
 * port, starting the webhook worker or opening a WhatsApp socket.
 */

/**
 * 2 MB.
 *
 * This is the ceiling on any request body, and it is deliberately modest: the
 * body is read and parsed BEFORE the auth hook can reject a caller, so whatever
 * this number is, an unauthenticated client can make the process buffer it.
 * Inline `base64` media is therefore capped here in practice — large files are
 * sent by `url`, which Baileys streams and which keeps the request tiny.
 */
const BODY_LIMIT_BYTES = 2 * 1024 * 1024

/**
 * A coarse abuse guard, not a business throttle.
 *
 * Sized generously because in normal operation there is exactly one caller
 * (Laravel), so a tight per-IP limit would cap the whole integration's
 * throughput rather than stopping an attacker. Campaign pacing, daily caps and
 * per-number throttling are Laravel's job and are not reimplemented here.
 *
 * In-memory and therefore per node: a fleet of N nodes allows N times this.
 * That is the right trade for a DoS guard — the alternative is a Redis round
 * trip on every request to protect against something this already bounds.
 */
const RATE_LIMIT_MAX = 1200
const RATE_LIMIT_WINDOW = '1 minute'

const ERROR_CODES = {
  notFound: 'not_found',
  internal: 'internal_error',
  payloadTooLarge: 'payload_too_large',
  unsupportedMediaType: 'unsupported_media_type',
  invalidBody: 'invalid_body',
  rateLimited: 'rate_limited',
  badRequest: 'bad_request',
} as const

/** Path without the query string — a query can carry caller-supplied values. */
function pathOf(url: string): string {
  const queryAt = url.indexOf('?')

  return queryAt === -1 ? url : url.slice(0, queryAt)
}

function errorCodeFor(error: FastifyError, status: number): string {
  switch (error.code) {
    case 'FST_ERR_CTP_BODY_TOO_LARGE':
      return ERROR_CODES.payloadTooLarge
    case 'FST_ERR_CTP_INVALID_MEDIA_TYPE':
    case 'FST_ERR_CTP_INVALID_CONTENT_LENGTH':
      return ERROR_CODES.unsupportedMediaType
    default:
      break
  }

  if (error.validation) {
    return ERROR_CODES.invalidBody
  }

  if (status === 429) {
    return ERROR_CODES.rateLimited
  }

  if (status === 404) {
    return ERROR_CODES.notFound
  }

  return status >= 500 ? ERROR_CODES.internal : ERROR_CODES.badRequest
}

export async function buildApp(): Promise<FastifyInstance> {
  const configuration = env()
  const isProduction = configuration.NODE_ENV === 'production'

  const app = Fastify({
    // Widened to FastifyBaseLogger deliberately: passing pino's concrete Logger
    // would make Fastify infer it into every generic, and route plugins typed
    // against the default FastifyInstance would then no longer match.
    loggerInstance: logger() as FastifyBaseLogger,
    bodyLimit: BODY_LIMIT_BYTES,
    // Fastify's own request id. The gateway's correlation id is set by
    // registerRequestContext, which honours an inbound X-Request-Id; this one
    // exists so Fastify's internal logging is never id-less.
    genReqId: () => randomUUID(),
    // Nested under routerOptions: the top-level form is deprecated in Fastify 5
    // and removed in 6, and it warns on every boot.
    routerOptions: { ignoreTrailingSlash: true },
  })

  // First: every later hook, including the auth rejections, needs a request id
  // to log against. Registered at the root so it covers signed and signature-
  // authorised routes alike.
  await registerRequestContext(app)

  /**
   * Rate limiting sits at the root, ahead of authentication.
   *
   * Deliberately before rather than after: HMAC verification and the nonce
   * round trip are the expensive part of a request, so a flood should be turned
   * away before it can spend them. Health probes are exempt — an orchestrator
   * throttled out of its own liveness check would restart a healthy container.
   */
  await app.register(rateLimit, {
    max: RATE_LIMIT_MAX,
    timeWindow: RATE_LIMIT_WINDOW,
    allowList: (request: FastifyRequest) => AUTH_EXEMPT_PATHS.includes(pathOf(request.url)),
    errorResponseBuilder: (_request, context) => ({
      error: {
        code: ERROR_CODES.rateLimited,
        message: `Rate limit exceeded: at most ${context.max} requests per ${context.after}. Retry in ${context.ttl}ms.`,
      },
    }),
  })

  /**
   * Everything authenticated by the Laravel HMAC scheme lives in one
   * encapsulated scope, so the auth hooks apply by construction rather than by
   * each route remembering to ask for them. Health is inside it too and is
   * exempted by path (AUTH_EXEMPT_PATHS) — keeping the exemption list as the
   * single, auditable statement of what is public.
   */
  await app.register(async (secured) => {
    await registerApiAuth(secured, { nonceStore: new PrismaNonceStore() })

    await secured.register(healthRoutes)
    await secured.register(instanceRoutes)
    await secured.register(messageRoutes)
    await secured.register(pollRoutes)
    await secured.register(diagnosticsRoutes)
    await secured.register(webhookRoutes)
  })

  /**
   * Media is registered OUTSIDE that scope, because it cannot be authenticated
   * the same way: the fetcher of a media URL holds no signing secret and cannot
   * mint a nonce. Its authorisation is the signature embedded in the URL — see
   * media.routes.ts. This is the only route in the process that the HMAC hooks
   * do not cover, and it is isolated here so that fact is impossible to miss.
   */
  await app.register(mediaRoutes)

  app.setNotFoundHandler((request, reply) =>
    reply.status(404).send({
      error: {
        code: ERROR_CODES.notFound,
        message: `No route for ${request.method} ${pathOf(request.url)}.`,
      },
    }),
  )

  app.setErrorHandler((error: FastifyError, request, reply) => {
    const status = typeof error.statusCode === 'number' && error.statusCode >= 400 ? error.statusCode : 500
    const code = errorCodeFor(error, status)
    const log = requestLogger(request)

    const context = {
      requestId: request.requestId,
      method: request.method,
      path: pathOf(request.url),
      status,
      code,
    }

    if (status >= 500) {
      // The stack goes to the log, where the operator is — never to the caller.
      log.error({ ...context, err: error }, 'Request failed')
    } else {
      log.warn({ ...context, reason: error.message }, 'Request rejected')
    }

    /**
     * A 5xx message is replaced in production rather than forwarded. Internal
     * error text routinely carries table names, file paths and fragments of
     * queries; none of that is actionable by the caller and all of it is useful
     * to an attacker. The request id is returned instead, which is what turns a
     * support ticket into a log lookup.
     */
    const message =
      status >= 500 && isProduction
        ? `The gateway encountered an internal error. Quote request id ${request.requestId} when reporting it.`
        : error.message

    return reply.status(status).send({ error: { code, message } })
  })

  return app
}
