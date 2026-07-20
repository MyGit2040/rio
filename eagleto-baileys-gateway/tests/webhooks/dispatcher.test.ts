import pino from 'pino'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { loadEnv, setEnvForTesting } from '../../src/config/env.js'
import { setLoggerForTesting } from '../../src/config/logger.js'
import { encrypt } from '../../src/security/encryption.js'
import { signWebhook, verifyWebhookSignature } from '../../src/webhooks/webhook-signer.js'

// Hoisted so the mock factory (which vitest lifts above the imports) can close
// over the same object the assertions read.
const { prismaMock } = vi.hoisted(() => ({
  prismaMock: {
    webhookEvent: {
      create: vi.fn(),
      findUnique: vi.fn(),
      update: vi.fn(),
      updateMany: vi.fn(),
      findMany: vi.fn(),
    },
  },
}))

vi.mock('../../src/database/client.js', () => ({ prisma: prismaMock }))

const { deliverWebhook, enqueueWebhook } = await import('../../src/webhooks/webhook-dispatcher.js')

const WEBHOOK_URL = 'https://laravel.test/api/gateway/webhooks'
const PLATFORM_SECRET = 'whsec_platform'
const MAX_ATTEMPTS = 5

const OCCURRED_AT = new Date('2026-01-01T00:00:00.000Z')

function fixtureEnv() {
  return loadEnv({
    NODE_ENV: 'test',
    APP_NODE_ID: 'test-node',
    DATABASE_URL: 'postgresql://localhost/test',
    REDIS_URL: 'redis://localhost:6379',
    MASTER_ENCRYPTION_KEY: 'a'.repeat(64),
    LARAVEL_API_KEY: 'api-key',
    LARAVEL_SIGNING_SECRET: 'inbound-secret',
    LARAVEL_WEBHOOK_URL: WEBHOOK_URL,
    WEBHOOK_SIGNING_SECRET: PLATFORM_SECRET,
    WEBHOOK_MAX_ATTEMPTS: String(MAX_ATTEMPTS),
    WEBHOOK_TIMEOUT_MS: '15000',
  } as NodeJS.ProcessEnv)
}

/** A persisted row as deliverWebhook loads it. */
function storedEvent(overrides: Record<string, unknown> = {}) {
  return {
    id: 'evt_abc123',
    instanceId: 'inst_1',
    eventType: 'message.delivered',
    eventVersion: '1.0',
    payload: { whatsapp_message_id: 'wam_1', recipient: '971500000000@s.whatsapp.net' },
    metadata: { tenant_id: 42, campaign_id: 'camp_7' },
    status: 'PENDING',
    attempts: 0,
    nextAttemptAt: OCCURRED_AT,
    deliveredAt: null,
    lastStatusCode: null,
    lastError: null,
    occurredAt: OCCURRED_AT,
    instance: null,
    ...overrides,
  }
}

function fetchMockReturning(response: Response | Promise<Response>) {
  const mock = vi.fn().mockReturnValue(Promise.resolve(response))
  vi.stubGlobal('fetch', mock)

  return mock
}

function lastUpdateData(): Record<string, unknown> {
  const call = prismaMock.webhookEvent.update.mock.calls.at(-1)

  expect(call).toBeDefined()

  return (call as unknown as [{ data: Record<string, unknown> }])[0].data
}

function sentRequest() {
  const mock = globalThis.fetch as unknown as ReturnType<typeof vi.fn>
  const call = mock.mock.calls[0]

  expect(call).toBeDefined()

  const [url, init] = call as unknown as [string, { body: string; headers: Record<string, string> }]

  return { url, body: init.body, headers: init.headers }
}

beforeEach(() => {
  setEnvForTesting(fixtureEnv())
  setLoggerForTesting(pino({ level: 'silent' }))

  prismaMock.webhookEvent.create.mockReset()
  prismaMock.webhookEvent.findUnique.mockReset()
  prismaMock.webhookEvent.update.mockReset()
  prismaMock.webhookEvent.updateMany.mockReset()
  prismaMock.webhookEvent.findMany.mockReset()

  prismaMock.webhookEvent.update.mockResolvedValue({})
})

afterEach(() => {
  vi.unstubAllGlobals()
  setEnvForTesting(null)
  setLoggerForTesting(null)
})

describe('enqueueWebhook', () => {
  it('persists a PENDING row and returns its id', async () => {
    prismaMock.webhookEvent.create.mockResolvedValue({ id: 'evt_new' })

    const id = await enqueueWebhook({
      eventType: 'message.sent',
      instanceId: 'inst_1',
      data: { whatsapp_message_id: 'wam_9' },
      metadata: { tenant_id: 7 },
      occurredAt: OCCURRED_AT,
    })

    expect(id).toBe('evt_new')

    const { data } = prismaMock.webhookEvent.create.mock.calls[0]![0] as { data: Record<string, unknown> }

    expect(data).toMatchObject({
      instanceId: 'inst_1',
      eventType: 'message.sent',
      payload: { whatsapp_message_id: 'wam_9' },
      metadata: { tenant_id: 7 },
      status: 'PENDING',
      attempts: 0,
      occurredAt: OCCURRED_AT,
    })
    expect(data['nextAttemptAt']).toBeInstanceOf(Date)
  })

  it('performs NO network I/O — an event must be durable before anything is sent', async () => {
    const fetchMock = fetchMockReturning(new Response('', { status: 200 }))
    prismaMock.webhookEvent.create.mockResolvedValue({ id: 'evt_new' })

    await enqueueWebhook({ eventType: 'instance.ready', data: {} })

    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('accepts an instance-less event and defaults its metadata', async () => {
    prismaMock.webhookEvent.create.mockResolvedValue({ id: 'evt_new' })

    await enqueueWebhook({ eventType: 'gateway.health_warning', data: { level: 'warning' } })

    const { data } = prismaMock.webhookEvent.create.mock.calls[0]![0] as { data: Record<string, unknown> }

    expect(data['instanceId']).toBeNull()
    expect(data['metadata']).toEqual({})
  })
})

describe('deliverWebhook — success', () => {
  it('marks a 2xx response DELIVERED and stamps deliveredAt', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent())
    fetchMockReturning(new Response('{"ok":true}', { status: 200 }))

    const result = await deliverWebhook('evt_abc123')

    expect(result).toEqual({ delivered: true, statusCode: 200 })

    const data = lastUpdateData()

    expect(data['status']).toBe('DELIVERED')
    expect(data['attempts']).toBe(1)
    expect(data['deliveredAt']).toBeInstanceOf(Date)
    expect(data['lastStatusCode']).toBe(200)
    expect(data['lastError']).toBeNull()
  })

  it('treats any 2xx as success, not only 200', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent())
    fetchMockReturning(new Response(null, { status: 204 }))

    const result = await deliverWebhook('evt_abc123')

    expect(result.delivered).toBe(true)
    expect(lastUpdateData()['status']).toBe('DELIVERED')
  })

  it('does not re-send an event that is already DELIVERED', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(
      storedEvent({ status: 'DELIVERED', lastStatusCode: 200 }),
    )
    const fetchMock = fetchMockReturning(new Response('', { status: 200 }))

    const result = await deliverWebhook('evt_abc123')

    expect(result).toEqual({ delivered: true, statusCode: 200 })
    expect(fetchMock).not.toHaveBeenCalled()
    expect(prismaMock.webhookEvent.update).not.toHaveBeenCalled()
  })

  it('reports a missing event rather than throwing', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(null)
    const fetchMock = fetchMockReturning(new Response('', { status: 200 }))

    const result = await deliverWebhook('evt_missing')

    expect(result.delivered).toBe(false)
    expect(result.error).toMatch(/not found/i)
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('deliverWebhook — the signed request', () => {
  beforeEach(() => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent())
    fetchMockReturning(new Response('', { status: 200 }))
  })

  it('signs the EXACT body bytes that were sent', async () => {
    await deliverWebhook('evt_abc123')

    const { body, headers } = sentRequest()

    // Recompute over the literal string handed to fetch. If the dispatcher ever
    // re-serialised the envelope for the request, key order or number
    // formatting could differ and Laravel would reject a valid event.
    expect(signWebhook(PLATFORM_SECRET, headers['X-Eagleto-Timestamp']!, body)).toBe(
      headers['X-Eagleto-Signature'],
    )

    expect(
      verifyWebhookSignature(
        PLATFORM_SECRET,
        headers['X-Eagleto-Timestamp']!,
        body,
        headers['X-Eagleto-Signature']!,
      ),
    ).toBe(true)
  })

  it('fails verification if a single byte of the transmitted body is altered', async () => {
    await deliverWebhook('evt_abc123')

    const { body, headers } = sentRequest()

    expect(
      verifyWebhookSignature(
        PLATFORM_SECRET,
        headers['X-Eagleto-Timestamp']!,
        `${body} `,
        headers['X-Eagleto-Signature']!,
      ),
    ).toBe(false)
  })

  it('sends the full header set', async () => {
    await deliverWebhook('evt_abc123')

    const { url, headers } = sentRequest()

    expect(url).toBe(WEBHOOK_URL)
    expect(headers['Content-Type']).toBe('application/json')
    expect(headers['X-Eagleto-Event-ID']).toBe('evt_abc123')
    expect(headers['X-Eagleto-Signature']).toMatch(/^[0-9a-f]{64}$/)
    expect(Number(headers['X-Eagleto-Timestamp'])).toBeGreaterThan(1_700_000_000)
  })

  it('sends the agreed envelope shape and nothing else', async () => {
    await deliverWebhook('evt_abc123')

    const envelope = JSON.parse(sentRequest().body) as Record<string, unknown>

    expect(Object.keys(envelope).sort()).toEqual(
      ['data', 'event_id', 'event_type', 'event_version', 'instance_id', 'metadata', 'occurred_at'].sort(),
    )
    expect(envelope).toMatchObject({
      event_id: 'evt_abc123',
      event_type: 'message.delivered',
      event_version: '1.0',
      occurred_at: OCCURRED_AT.toISOString(),
      instance_id: 'inst_1',
      data: { whatsapp_message_id: 'wam_1', recipient: '971500000000@s.whatsapp.net' },
      metadata: { tenant_id: 42, campaign_id: 'camp_7' },
    })
  })

  it('uses the per-instance URL and secret when the instance overrides them', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(
      storedEvent({
        instance: {
          webhookUrl: 'https://tenant.example/hooks/whatsapp',
          webhookSecretEnc: encrypt('whsec_tenant_specific'),
        },
      }),
    )

    await deliverWebhook('evt_abc123')

    const { url, body, headers } = sentRequest()

    expect(url).toBe('https://tenant.example/hooks/whatsapp')
    expect(signWebhook('whsec_tenant_specific', headers['X-Eagleto-Timestamp']!, body)).toBe(
      headers['X-Eagleto-Signature'],
    )
    // The platform secret must NOT be able to verify a per-instance event.
    expect(
      verifyWebhookSignature(
        PLATFORM_SECRET,
        headers['X-Eagleto-Timestamp']!,
        body,
        headers['X-Eagleto-Signature']!,
      ),
    ).toBe(false)
  })
})

describe('deliverWebhook — failure and retry', () => {
  it('schedules a RETRY_WAIT on a 500', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent())
    fetchMockReturning(new Response('upstream exploded', { status: 500 }))

    const result = await deliverWebhook('evt_abc123')

    expect(result.delivered).toBe(false)
    expect(result.statusCode).toBe(500)

    const data = lastUpdateData()

    expect(data['status']).toBe('RETRY_WAIT')
    expect(data['attempts']).toBe(1)
    expect(data['lastStatusCode']).toBe(500)
    expect(data['lastError']).toContain('HTTP 500')
    expect(data['lastError']).toContain('upstream exploded')
    expect((data['nextAttemptAt'] as Date).getTime()).toBeGreaterThan(Date.now())
  })

  it('retries a 4xx too — a misconfigured route must not silently drop events', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent())
    fetchMockReturning(new Response('not found', { status: 404 }))

    const result = await deliverWebhook('evt_abc123')

    expect(result.delivered).toBe(false)
    expect(lastUpdateData()['status']).toBe('RETRY_WAIT')
  })

  it('schedules a RETRY_WAIT with no status code on a network error', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent())

    const fetchMock = vi.fn().mockRejectedValue(new TypeError('fetch failed'))
    vi.stubGlobal('fetch', fetchMock)

    const result = await deliverWebhook('evt_abc123')

    expect(result.delivered).toBe(false)
    expect(result.statusCode).toBeUndefined()
    expect(result.error).toContain('fetch failed')

    const data = lastUpdateData()

    expect(data['status']).toBe('RETRY_WAIT')
    expect(data['lastStatusCode']).toBeNull()
  })

  it('names the timeout when the request is aborted', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent())

    const timeout = new Error('The operation was aborted')
    timeout.name = 'TimeoutError'

    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(timeout))

    const result = await deliverWebhook('evt_abc123')

    expect(result.error).toContain('timed out after 15000ms')
    expect(lastUpdateData()['status']).toBe('RETRY_WAIT')
  })

  it('backs off further on each successive failure', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent({ attempts: 0 }))
    fetchMockReturning(new Response('', { status: 503 }))
    await deliverWebhook('evt_abc123')
    const firstDelay = (lastUpdateData()['nextAttemptAt'] as Date).getTime() - Date.now()

    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent({ attempts: 2 }))
    fetchMockReturning(new Response('', { status: 503 }))
    await deliverWebhook('evt_abc123')
    const laterDelay = (lastUpdateData()['nextAttemptAt'] as Date).getTime() - Date.now()

    expect(laterDelay).toBeGreaterThan(firstDelay)
  })

  it('DEAD_LETTERs once attempts reach WEBHOOK_MAX_ATTEMPTS', async () => {
    // One short of the limit: this failure is the last one it gets.
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent({ attempts: MAX_ATTEMPTS - 1 }))
    fetchMockReturning(new Response('still broken', { status: 500 }))

    const result = await deliverWebhook('evt_abc123')

    expect(result.delivered).toBe(false)

    const data = lastUpdateData()

    expect(data['status']).toBe('DEAD_LETTER')
    expect(data['attempts']).toBe(MAX_ATTEMPTS)
    expect(data['lastError']).toContain('still broken')
  })

  it('keeps retrying right up to the final attempt', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(storedEvent({ attempts: MAX_ATTEMPTS - 2 }))
    fetchMockReturning(new Response('', { status: 500 }))

    await deliverWebhook('evt_abc123')

    expect(lastUpdateData()['status']).toBe('RETRY_WAIT')
    expect(lastUpdateData()['attempts']).toBe(MAX_ATTEMPTS - 1)
  })

  it('does not send an event whose per-instance secret cannot be decrypted', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(
      storedEvent({ instance: { webhookUrl: null, webhookSecretEnc: 'v1.garbage.not.a.real.envelope.at' } }),
    )
    const fetchMock = fetchMockReturning(new Response('', { status: 200 }))

    const result = await deliverWebhook('evt_abc123')

    // Signing with the wrong secret would look like a forgery to Laravel, so
    // the attempt is failed and surfaced rather than sent.
    expect(result.delivered).toBe(false)
    expect(fetchMock).not.toHaveBeenCalled()
    expect(lastUpdateData()['status']).toBe('RETRY_WAIT')
  })
})
