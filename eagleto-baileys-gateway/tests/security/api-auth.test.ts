import { randomUUID } from 'node:crypto'

import Fastify, { type FastifyInstance } from 'fastify'
import pino from 'pino'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { setEnvForTesting } from '../../src/config/env.js'
import { setLoggerForTesting } from '../../src/config/logger.js'
import {
  HEADER_KEY,
  HEADER_NONCE,
  HEADER_SIGNATURE,
  HEADER_TIMESTAMP,
  registerApiAuth,
} from '../../src/api/middleware/api-auth.js'
import { registerRequestContext } from '../../src/api/middleware/request-context.js'
import type { NonceStore } from '../../src/security/nonce-store.js'
import { signRequest } from '../../src/security/request-signature.js'
import { testEnv, TEST_API_KEY, TEST_SIGNING_SECRET } from '../helpers/env-fixture.js'

/** Replay protection without a database. */
class InMemoryNonceStore implements NonceStore {
  private readonly seen = new Set<string>()

  async consume(nonce: string): Promise<boolean> {
    if (this.seen.has(nonce)) {
      return false
    }

    this.seen.add(nonce)

    return true
  }
}

class UnavailableNonceStore implements NonceStore {
  async consume(): Promise<boolean> {
    throw new Error('connection refused')
  }
}

let logLines: string[] = []

function installTestLogger(): void {
  logLines = []
  setLoggerForTesting(
    pino(
      { level: 'warn' },
      {
        write(line: string) {
          logLines.push(line)
        },
      },
    ),
  )
}

interface SignOptions {
  method: string
  path: string
  body?: string
  key?: string
  secret?: string
  timestamp?: string
  nonce?: string
}

function signedHeaders(options: SignOptions): Record<string, string> {
  const {
    method,
    path,
    body = '',
    key = TEST_API_KEY,
    secret = TEST_SIGNING_SECRET,
    timestamp = String(Math.floor(Date.now() / 1000)),
    nonce = randomUUID(),
  } = options

  return {
    'content-type': 'application/json',
    [HEADER_KEY]: key,
    [HEADER_TIMESTAMP]: timestamp,
    [HEADER_NONCE]: nonce,
    [HEADER_SIGNATURE]: signRequest(secret, timestamp, nonce, method, path, body),
  }
}

async function buildApp(
  envOverrides: NodeJS.ProcessEnv = {},
  nonceStore: NonceStore = new InMemoryNonceStore(),
): Promise<FastifyInstance> {
  setEnvForTesting(testEnv(envOverrides))

  const app = Fastify()

  await registerRequestContext(app)
  await registerApiAuth(app, { nonceStore })

  app.post('/v1/instances/abc/messages', async (request) => ({ echo: request.body }))
  app.get('/v1/instances/abc/diagnostics', async () => ({ sockets: 1 }))
  app.get('/health/live', async () => ({ status: 'ok' }))
  app.get('/health/ready', async () => ({ status: 'ok' }))

  await app.ready()

  return app
}

let app: FastifyInstance | null = null

beforeEach(() => {
  installTestLogger()
})

afterEach(async () => {
  await app?.close()
  app = null
  setEnvForTesting(null)
  setLoggerForTesting(null)
})

describe('registerApiAuth — accepted requests', () => {
  it('accepts a correctly signed POST', async () => {
    app = await buildApp()

    const path = '/v1/instances/abc/messages'
    const body = '{"to":"971500000000","text":"hello"}'

    const response = await app.inject({
      method: 'POST',
      url: path,
      headers: signedHeaders({ method: 'POST', path, body }),
      payload: body,
    })

    expect(response.statusCode).toBe(200)
    expect(response.json()).toEqual({ echo: { to: '971500000000', text: 'hello' } })
  })

  it('accepts a signed GET with no body', async () => {
    app = await buildApp()

    const path = '/v1/instances/abc/diagnostics'

    const response = await app.inject({
      method: 'GET',
      url: path,
      headers: signedHeaders({ method: 'GET', path }),
    })

    expect(response.statusCode).toBe(200)
  })

  it('signs the query string, so a signed URL with query is accepted verbatim', async () => {
    app = await buildApp()

    const path = '/v1/instances/abc/diagnostics?verbose=true'

    const response = await app.inject({
      method: 'GET',
      url: path,
      headers: signedHeaders({ method: 'GET', path }),
    })

    expect(response.statusCode).toBe(200)
  })
})

describe('registerApiAuth — rejected requests', () => {
  const path = '/v1/instances/abc/messages'
  const body = '{"to":"971500000000","text":"hello"}'

  it('rejects a missing signature header with 401', async () => {
    app = await buildApp()

    const headers = signedHeaders({ method: 'POST', path, body })
    delete headers[HEADER_SIGNATURE]

    const response = await app.inject({ method: 'POST', url: path, headers, payload: body })

    expect(response.statusCode).toBe(401)
    expect(response.json().error.code).toBe('missing_credentials')
  })

  it.each([HEADER_KEY, HEADER_TIMESTAMP, HEADER_NONCE])(
    'rejects a request missing %s with 401',
    async (header) => {
      app = await buildApp()

      const headers = signedHeaders({ method: 'POST', path, body })
      delete headers[header]

      const response = await app.inject({ method: 'POST', url: path, headers, payload: body })

      expect(response.statusCode).toBe(401)
      expect(response.json().error.code).toBe('missing_credentials')
    },
  )

  it('rejects an unknown API key with an opaque 401', async () => {
    app = await buildApp()

    const response = await app.inject({
      method: 'POST',
      url: path,
      headers: signedHeaders({ method: 'POST', path, body, key: 'not-the-key' }),
      payload: body,
    })

    expect(response.statusCode).toBe(401)
    expect(response.json().error.code).toBe('unauthorized')
  })

  it('rejects a signature made with the wrong secret', async () => {
    app = await buildApp()

    const response = await app.inject({
      method: 'POST',
      url: path,
      headers: signedHeaders({ method: 'POST', path, body, secret: 'wrong-secret' }),
      payload: body,
    })

    expect(response.statusCode).toBe(401)
    expect(response.json().error.code).toBe('unauthorized')
  })

  it('rejects a body tampered with after signing', async () => {
    app = await buildApp()

    const headers = signedHeaders({ method: 'POST', path, body })

    const response = await app.inject({
      method: 'POST',
      url: path,
      headers,
      payload: '{"to":"971599999999","text":"hello"}',
    })

    expect(response.statusCode).toBe(401)
    expect(response.json().error.code).toBe('unauthorized')
  })

  it('rejects a path tampered with after signing', async () => {
    app = await buildApp()

    const headers = signedHeaders({ method: 'POST', path, body })

    const response = await app.inject({
      method: 'POST',
      url: '/v1/instances/abc/messages?admin=1',
      headers,
      payload: body,
    })

    expect(response.statusCode).toBe(401)
    expect(response.json().error.code).toBe('unauthorized')
  })

  it('rejects an expired timestamp and says so, so a wrong clock is fixable', async () => {
    app = await buildApp()

    const timestamp = String(Math.floor(Date.now() / 1000) - 3600)

    const response = await app.inject({
      method: 'POST',
      url: path,
      headers: signedHeaders({ method: 'POST', path, body, timestamp }),
      payload: body,
    })

    expect(response.statusCode).toBe(401)
    expect(response.json().error.code).toBe('stale_timestamp')
  })

  it('rejects a future timestamp', async () => {
    app = await buildApp()

    const timestamp = String(Math.floor(Date.now() / 1000) + 3600)

    const response = await app.inject({
      method: 'POST',
      url: path,
      headers: signedHeaders({ method: 'POST', path, body, timestamp }),
      payload: body,
    })

    expect(response.statusCode).toBe(401)
    expect(response.json().error.code).toBe('stale_timestamp')
  })

  it('rejects a replayed nonce on the second presentation', async () => {
    app = await buildApp()

    const headers = signedHeaders({ method: 'POST', path, body })

    const first = await app.inject({ method: 'POST', url: path, headers, payload: body })
    const replay = await app.inject({ method: 'POST', url: path, headers, payload: body })

    expect(first.statusCode).toBe(200)
    expect(replay.statusCode).toBe(401)
    expect(replay.json().error.code).toBe('unauthorized')
  })

  it('does not spend a nonce on a request that fails signature verification', async () => {
    const store = new InMemoryNonceStore()
    app = await buildApp({}, store)

    const nonce = randomUUID()

    const forged = await app.inject({
      method: 'POST',
      url: path,
      headers: signedHeaders({ method: 'POST', path, body, nonce, secret: 'wrong-secret' }),
      payload: body,
    })

    // The same nonce must still be usable by the legitimate caller: an
    // unauthenticated attacker must not be able to burn nonces.
    const genuine = await app.inject({
      method: 'POST',
      url: path,
      headers: signedHeaders({ method: 'POST', path, body, nonce }),
      payload: body,
    })

    expect(forged.statusCode).toBe(401)
    expect(genuine.statusCode).toBe(200)
  })

  it('answers 503, not 401, when the nonce store is unavailable', async () => {
    app = await buildApp({}, new UnavailableNonceStore())

    const response = await app.inject({
      method: 'POST',
      url: path,
      headers: signedHeaders({ method: 'POST', path, body }),
      payload: body,
    })

    expect(response.statusCode).toBe(503)
    expect(response.json().error.code).toBe('nonce_store_unavailable')
  })

  it('reports malformed JSON only after authentication succeeds', async () => {
    app = await buildApp()

    const malformed = '{"to": '

    const authenticated = await app.inject({
      method: 'POST',
      url: path,
      headers: signedHeaders({ method: 'POST', path, body: malformed }),
      payload: malformed,
    })

    expect(authenticated.statusCode).toBe(400)
    expect(authenticated.json().error.code).toBe('invalid_json')

    // Unauthenticated, the same malformed body reveals nothing about parsing.
    const anonymous = await app.inject({
      method: 'POST',
      url: path,
      headers: { 'content-type': 'application/json' },
      payload: malformed,
    })

    expect(anonymous.statusCode).toBe(401)
    expect(anonymous.json().error.code).toBe('missing_credentials')
  })

  it('returns the documented error envelope shape', async () => {
    app = await buildApp()

    const response = await app.inject({ method: 'POST', url: path, payload: body, headers: { 'content-type': 'application/json' } })

    expect(response.json()).toEqual({
      error: { code: expect.any(String), message: expect.any(String) },
    })
  })
})

describe('registerApiAuth — exempt paths', () => {
  it.each(['/health/live', '/health/ready'])('serves %s without credentials', async (url) => {
    app = await buildApp()

    const response = await app.inject({ method: 'GET', url })

    expect(response.statusCode).toBe(200)
  })

  it('still authenticates diagnostics — no unauthenticated introspection', async () => {
    app = await buildApp()

    const response = await app.inject({ method: 'GET', url: '/v1/instances/abc/diagnostics' })

    expect(response.statusCode).toBe(401)
  })

  it('does not treat a traversal onto an exempt prefix as exempt', async () => {
    app = await buildApp()

    const response = await app.inject({
      method: 'GET',
      url: '/health/live/../instances/abc/diagnostics',
    })

    expect(response.statusCode).not.toBe(200)
  })
})

describe('registerApiAuth — IP allow-list', () => {
  const path = '/v1/instances/abc/diagnostics'

  it('allows a listed source address', async () => {
    app = await buildApp({ API_ALLOWED_IPS: '10.0.0.5, 10.0.0.6' })

    const response = await app.inject({
      method: 'GET',
      url: path,
      headers: signedHeaders({ method: 'GET', path }),
      remoteAddress: '10.0.0.5',
    })

    expect(response.statusCode).toBe(200)
  })

  it('blocks an unlisted source address with 403, before credentials are read', async () => {
    app = await buildApp({ API_ALLOWED_IPS: '10.0.0.5' })

    const response = await app.inject({
      method: 'GET',
      url: path,
      headers: signedHeaders({ method: 'GET', path }),
      remoteAddress: '203.0.113.9',
    })

    expect(response.statusCode).toBe(403)
    expect(response.json().error.code).toBe('ip_not_allowed')
  })

  it('treats an IPv4-mapped IPv6 address as the same host', async () => {
    app = await buildApp({ API_ALLOWED_IPS: '10.0.0.5' })

    const response = await app.inject({
      method: 'GET',
      url: path,
      headers: signedHeaders({ method: 'GET', path }),
      remoteAddress: '::ffff:10.0.0.5',
    })

    expect(response.statusCode).toBe(200)
  })

  it('accepts any source address when the allow-list is empty', async () => {
    app = await buildApp({ API_ALLOWED_IPS: '' })

    const response = await app.inject({
      method: 'GET',
      url: path,
      headers: signedHeaders({ method: 'GET', path }),
      remoteAddress: '203.0.113.9',
    })

    expect(response.statusCode).toBe(200)
  })

  it('leaves the health probe reachable from any address', async () => {
    app = await buildApp({ API_ALLOWED_IPS: '10.0.0.5' })

    const response = await app.inject({
      method: 'GET',
      url: '/health/live',
      remoteAddress: '172.17.0.1',
    })

    expect(response.statusCode).toBe(200)
  })
})

describe('registerApiAuth — logging discipline', () => {
  const path = '/v1/instances/abc/messages'
  const body = '{"to":"971500000000","text":"top secret"}'

  it('logs the reason but never the key, signature or body', async () => {
    app = await buildApp()

    const headers = signedHeaders({ method: 'POST', path, body, key: 'leaked-key-value' })

    const response = await app.inject({ method: 'POST', url: path, headers, payload: body })
    const written = logLines.join('\n')

    expect(response.statusCode).toBe(401)
    expect(written).toContain('unknown_api_key')
    expect(written).not.toContain('leaked-key-value')
    expect(written).not.toContain(headers[HEADER_SIGNATURE])
    expect(written).not.toContain('top secret')
    expect(written).not.toContain(TEST_SIGNING_SECRET)
  })

  it('logs the path without its query string', async () => {
    app = await buildApp()

    await app.inject({
      method: 'GET',
      url: '/v1/instances/abc/diagnostics?token=should-not-be-logged',
    })

    const written = logLines.join('\n')

    expect(written).toContain('/v1/instances/abc/diagnostics')
    expect(written).not.toContain('should-not-be-logged')
  })
})

describe('registerRequestContext', () => {
  it('returns a generated request id on every response', async () => {
    app = await buildApp()

    const response = await app.inject({ method: 'GET', url: '/health/live' })

    expect(response.headers['x-request-id']).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/,
    )
  })

  it('sets the request id on rejected responses too', async () => {
    app = await buildApp()

    const response = await app.inject({ method: 'GET', url: '/v1/instances/abc/diagnostics' })

    expect(response.statusCode).toBe(401)
    expect(response.headers['x-request-id']).toBeDefined()
  })

  it('echoes a safe upstream request id so a call can be traced end to end', async () => {
    app = await buildApp()

    const response = await app.inject({
      method: 'GET',
      url: '/health/live',
      headers: { 'x-request-id': 'laravel-req-0001' },
    })

    expect(response.headers['x-request-id']).toBe('laravel-req-0001')
  })

  it('replaces an unsafe upstream request id rather than echoing it', async () => {
    app = await buildApp()

    const response = await app.inject({
      method: 'GET',
      url: '/health/live',
      headers: { 'x-request-id': 'bad\r\nInjected-Header: 1' },
    })

    expect(response.headers['x-request-id']).not.toContain('Injected-Header')
  })
})
