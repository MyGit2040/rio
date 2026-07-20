import { createReadStream } from 'node:fs'
import { stat } from 'node:fs/promises'
import { basename, resolve, sep } from 'node:path'

import type { FastifyInstance, FastifyReply } from 'fastify'

import { verifyMediaUrlSignature } from '../../baileys/media-handler.js'
import { env } from '../../config/env.js'
import { requestLogger } from '../middleware/request-context.js'

/**
 * Download endpoint for stored inbound media.
 *
 * This is the one route that is NOT authenticated by the HMAC header scheme,
 * and the reason is structural: the consumer of this URL is whatever fetches
 * the file — Laravel's HTTP client, a queue worker, sometimes a browser — none
 * of which holds the signing secret or can produce a per-request nonce. The URL
 * itself carries the authorisation instead: `media-handler` signs
 * `<path>.<expiry>` with the shared secret and embeds both, so possession of a
 * valid, unexpired URL is the credential.
 *
 * That makes the signature check the whole of the security boundary here, so it
 * runs before anything else touches the filesystem — including before the
 * existence check. Answering 404 for an unsigned request would turn this route
 * into an oracle for which files exist, which is a slow enumeration of every
 * customer's stored media.
 */

/**
 * Exactly the shape `MediaHandler.storeInbound` mints:
 * `<instanceId>/<sha256><extension>`. Enforced independently of the signature
 * so a traversal attempt is rejected on structure alone, without relying on the
 * HMAC to be the only thing standing between a caller and the filesystem.
 */
const STORED_PATH_PATTERN = /^[A-Za-z0-9_-]{1,64}\/[A-Za-z0-9]{1,128}\.[A-Za-z0-9]{1,8}$/

const CODES = {
  invalidPath: 'invalid_media_path',
  badSignature: 'invalid_signature',
  notFound: 'media_not_found',
} as const

function fail(reply: FastifyReply, status: number, code: string, message: string): FastifyReply {
  return reply.status(status).send({ error: { code, message } })
}

interface MediaQuery {
  expires?: string
  signature?: string
}

export async function mediaRoutes(app: FastifyInstance): Promise<void> {
  app.get<{ Params: Record<string, string | undefined>; Querystring: MediaQuery }>(
    '/media/*',
    async (request, reply) => {
      const relativePath = request.params['*'] ?? ''

      if (!STORED_PATH_PATTERN.test(relativePath)) {
        return fail(reply, 400, CODES.invalidPath, 'That is not a valid media path.')
      }

      const expiresRaw = request.query.expires ?? ''
      const signature = request.query.signature ?? ''
      const expiresAtEpoch = Number(expiresRaw)

      /**
       * One opaque answer for every authorisation failure — forged signature,
       * tampered path, expired link. Distinguishing them would tell a caller
       * holding an expired URL that the path is real, and tell a caller probing
       * signatures how close they got.
       */
      if (
        !Number.isInteger(expiresAtEpoch) ||
        !verifyMediaUrlSignature(relativePath, expiresAtEpoch, signature)
      ) {
        return fail(
          reply,
          403,
          CODES.badSignature,
          'This media link is not valid or has expired. Media URLs are short-lived by design.',
        )
      }

      const root = resolve(env().MEDIA_STORAGE_PATH)
      const fullPath = resolve(root, relativePath)

      // Defence in depth: the signature already proves we issued this path, but
      // a containment check costs nothing and means a signing mistake can never
      // become an arbitrary file read.
      if (fullPath !== root && !fullPath.startsWith(root + sep)) {
        return fail(reply, 400, CODES.invalidPath, 'That is not a valid media path.')
      }

      let sizeBytes: number

      try {
        const stats = await stat(fullPath)

        if (!stats.isFile()) {
          return fail(reply, 404, CODES.notFound, 'That media file is no longer available.')
        }

        sizeBytes = stats.size
      } catch {
        // Retention is short by design, so "gone" is the normal end state for
        // every file here rather than an error worth logging.
        return fail(
          reply,
          404,
          CODES.notFound,
          'That media file is no longer available. Inbound media is retained only briefly.',
        )
      }

      /**
       * Served as an opaque download, never inline.
       *
       * The real MIME type already reached Laravel in the webhook descriptor, so
       * re-deriving it here would only duplicate media-handler's table and let
       * the two drift. More importantly, a stored file served inline with a
       * guessed content type is a stored-XSS vector the moment anything renders
       * it in a browser; octet-stream plus an attachment disposition and nosniff
       * removes that entirely.
       */
      reply
        .header('Content-Type', 'application/octet-stream')
        .header('Content-Length', sizeBytes)
        .header('Content-Disposition', `attachment; filename="${basename(relativePath)}"`)
        .header('X-Content-Type-Options', 'nosniff')
        // The URL is a bearer credential; a shared cache holding the response
        // would outlive the expiry the signature enforces.
        .header('Cache-Control', 'private, no-store')

      const stream = createReadStream(fullPath)

      stream.on('error', (error) => {
        requestLogger(request).error({ err: error, relativePath }, 'Failed while streaming media file')
      })

      return reply.send(stream)
    },
  )
}
