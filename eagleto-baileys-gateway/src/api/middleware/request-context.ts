import { randomUUID } from 'node:crypto'

import type { FastifyInstance, FastifyRequest } from 'fastify'
import type { Logger } from 'pino'

import { contextLogger } from '../../config/logger.js'

/**
 * Per-request identity.
 *
 * Every log line the gateway writes while handling a request carries the same
 * requestId, and the id goes back to the caller in a header — so a Laravel
 * operator holding a failed response can find the exact server-side trail
 * without correlating on timestamps.
 *
 * Register this BEFORE the auth middleware: hooks run in registration order,
 * and an auth rejection should already have an id to log against.
 */

declare module 'fastify' {
  interface FastifyRequest {
    requestId: string
    /** Null until the onRequest hook runs; read it through requestLogger(). */
    contextLog: Logger | null
  }
}

const REQUEST_ID_HEADER = 'x-request-id'
const RESPONSE_HEADER = 'X-Request-Id'

/**
 * An inbound id is echoed straight back in a response header, so it is treated
 * as untrusted input: only an opaque, bounded token is accepted. Anything else
 * is replaced rather than sanitised, because a partially-cleaned id is no
 * longer the id the caller is looking for.
 */
const SAFE_REQUEST_ID = /^[A-Za-z0-9._-]{1,128}$/

export async function registerRequestContext(app: FastifyInstance): Promise<void> {
  // Declared up front so every request object has the same shape from birth.
  app.decorateRequest('requestId', '')
  app.decorateRequest('contextLog', null)

  app.addHook('onRequest', async (request, reply) => {
    const incoming = request.headers[REQUEST_ID_HEADER]
    const candidate = typeof incoming === 'string' ? incoming.trim() : ''

    request.requestId = SAFE_REQUEST_ID.test(candidate) ? candidate : randomUUID()
    request.contextLog = contextLogger({ requestId: request.requestId })

    // Set on the way in, so the header is present on error responses and on
    // replies sent from a hook that aborts before the handler ever runs.
    reply.header(RESPONSE_HEADER, request.requestId)
  })
}

/**
 * The request's child logger, falling back to a fresh one if this middleware
 * was not registered — so a logging call can never be the reason a security
 * check fails to run.
 */
export function requestLogger(request: FastifyRequest): Logger {
  return request.contextLog ?? contextLogger({ requestId: request.requestId || 'unknown' })
}
