import pino from 'pino'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { loadEnv, setEnvForTesting } from '../../src/config/env.js'
import { setLoggerForTesting } from '../../src/config/logger.js'

/**
 * The webhook envelope must identify an instance the way LARAVEL knows it.
 *
 * An instance has two identities: the gateway's internal cuid, and the name
 * Laravel generated (whatsapp_instances.instance_name, stored here as
 * externalInstanceId). Laravel has never seen the cuid, and matches inbound
 * webhooks on the name.
 *
 * Sending the cuid therefore made every event unmatchable. Nothing errored —
 * Laravel acknowledged each delivery and discarded it — so devices never
 * reported ready and message statuses never advanced, while both sides looked
 * healthy. That silence is why this needs an explicit guard.
 */

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

const { deliverWebhook } = await import('../../src/webhooks/webhook-dispatcher.js')

const WEBHOOK_URL = 'https://laravel.test/webhooks/baileys'
const PLATFORM_SECRET = 'whsec_platform'

/** The two identities, deliberately unlike each other. */
const INTERNAL_ID = 'cmrtr228d0000qx07e7g2p01k'
const EXTERNAL_ID = 'demo-b8sae1yf'

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
    WEBHOOK_MAX_ATTEMPTS: '5',
    WEBHOOK_TIMEOUT_MS: '15000',
  })
}

function eventRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 'evt_1',
    instanceId: INTERNAL_ID,
    eventType: 'instance.ready',
    eventVersion: '1.0',
    payload: { some: 'detail' },
    metadata: { tenant_id: 1 },
    status: 'PENDING',
    attempts: 0,
    occurredAt: new Date('2026-01-01T00:00:00.000Z'),
    lastStatusCode: null,
    instance: {
      webhookUrl: null,
      webhookSecretEnc: null,
      externalInstanceId: EXTERNAL_ID,
    },
    ...overrides,
  }
}

describe('webhook envelope identity', () => {
  let sent: { body: string } | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    setEnvForTesting(fixtureEnv())
    setLoggerForTesting(pino({ level: 'silent' }))
    sent = null

    prismaMock.webhookEvent.update.mockResolvedValue({})

    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init: { body: string }) => {
        sent = { body: init.body }

        return new Response('{}', { status: 200 })
      }),
    )
  })

  it('identifies the instance by the name Laravel generated, not the internal cuid', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(eventRow())

    await deliverWebhook('evt_1')

    const envelope = JSON.parse(sent!.body) as Record<string, unknown>

    expect(envelope.instance_id).toBe(EXTERNAL_ID)
    expect(envelope.instance_id).not.toBe(INTERNAL_ID)
  })

  it('still carries the internal id for support, inside data', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(eventRow())

    await deliverWebhook('evt_1')

    const envelope = JSON.parse(sent!.body) as { data: Record<string, unknown> }

    expect(envelope.data.gateway_instance_id).toBe(INTERNAL_ID)
    // The original payload must survive alongside it.
    expect(envelope.data.some).toBe('detail')
  })

  it('falls back to the internal id when the instance row is gone', async () => {
    // A cascade-deleted instance leaves the relation null. Sending nothing at
    // all would be worse than sending an id Laravel cannot match: at least the
    // event still identifies itself in support logs.
    prismaMock.webhookEvent.findUnique.mockResolvedValue(eventRow({ instance: null }))

    await deliverWebhook('evt_1')

    const envelope = JSON.parse(sent!.body) as Record<string, unknown>

    expect(envelope.instance_id).toBe(INTERNAL_ID)
  })

  it('sends no instance id for an event that has no instance', async () => {
    prismaMock.webhookEvent.findUnique.mockResolvedValue(
      eventRow({ instanceId: null, instance: null, eventType: 'gateway.health_warning' }),
    )

    await deliverWebhook('evt_1')

    const envelope = JSON.parse(sent!.body) as Record<string, unknown>

    expect(envelope.instance_id).toBeNull()
  })
})
