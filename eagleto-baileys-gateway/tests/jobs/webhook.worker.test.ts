import pino from 'pino'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { loadEnv, setEnvForTesting } from '../../src/config/env.js'
import { setLoggerForTesting } from '../../src/config/logger.js'

const { prismaMock } = vi.hoisted(() => ({
  prismaMock: {
    webhookEvent: { findMany: vi.fn(), updateMany: vi.fn(), update: vi.fn(), findUnique: vi.fn(), create: vi.fn() },
  },
}))

vi.mock('../../src/database/client.js', () => ({ prisma: prismaMock }))

const { deliverWebhook } = vi.hoisted(() => ({ deliverWebhook: vi.fn() }))

vi.mock('../../src/webhooks/webhook-dispatcher.js', async (importOriginal) => {
  const actual = (await importOriginal()) as Record<string, unknown>
  return { ...actual, deliverWebhook }
})

const { WebhookWorker } = await import('../../src/jobs/webhook.worker.js')

beforeEach(() => {
  setEnvForTesting(
    loadEnv({
      APP_NODE_ID: 'n1',
      DATABASE_URL: 'postgresql://x/y',
      REDIS_URL: 'redis://x',
      MASTER_ENCRYPTION_KEY: 'a'.repeat(64),
      LARAVEL_API_KEY: 'k',
      LARAVEL_SIGNING_SECRET: 's',
      LARAVEL_WEBHOOK_URL: 'https://l.test/h',
      WEBHOOK_SIGNING_SECRET: 'w',
    } as NodeJS.ProcessEnv),
  )
  setLoggerForTesting(pino({ level: 'silent' }))
  vi.clearAllMocks()
  prismaMock.webhookEvent.updateMany.mockResolvedValue({ count: 0 })
})

describe('WebhookWorker', () => {
  it('delivers only the events it successfully claimed', async () => {
    prismaMock.webhookEvent.findMany
      .mockResolvedValueOnce([{ id: 'a' }, { id: 'b' }, { id: 'c' }])
      .mockResolvedValue([])

    // 'b' is claimed by another node: its conditional update matches 0 rows.
    prismaMock.webhookEvent.updateMany.mockImplementation(async (args: { where: { id?: string } }) => ({
      count: args.where.id === 'b' ? 0 : 1,
    }))

    const worker = new WebhookWorker({ idleDelayMs: 20, reclaimIntervalMs: 1_000_000 })
    worker.start()
    await new Promise((r) => setTimeout(r, 120))
    await worker.stop()

    const delivered = deliverWebhook.mock.calls.map((c) => c[0]).sort()
    expect(delivered).toEqual(['a', 'c'])
  })

  it('claims with a conditional update rather than a blind write', async () => {
    prismaMock.webhookEvent.findMany.mockResolvedValueOnce([{ id: 'a' }]).mockResolvedValue([])
    prismaMock.webhookEvent.updateMany.mockResolvedValue({ count: 1 })

    const worker = new WebhookWorker({ idleDelayMs: 20, reclaimIntervalMs: 1_000_000 })
    worker.start()
    await new Promise((r) => setTimeout(r, 80))
    await worker.stop()

    // The first updateMany of a pass is the startup stall sweep (it recovers
    // rows a crashed node left in DELIVERING); the claim is the one keyed by id.
    const claim = prismaMock.webhookEvent.updateMany.mock.calls
      .map((c) => c[0] as { where: { id?: string; status: unknown }; data: { status: string } })
      .find((c) => c.where.id !== undefined) as {
      where: { id: string; status: { in: string[] } }
      data: { status: string }
    }
    expect(claim.where.id).toBe('a')
    expect(claim.where.status.in).toEqual(['PENDING', 'RETRY_WAIT'])
    expect(claim.data.status).toBe('DELIVERING')
  })

  it('stops promptly, cutting the idle sleep short', async () => {
    prismaMock.webhookEvent.findMany.mockResolvedValue([])

    const worker = new WebhookWorker({ idleDelayMs: 5_000, reclaimIntervalMs: 1_000_000 })
    worker.start()
    await new Promise((r) => setTimeout(r, 50))

    const started = Date.now()
    await worker.stop()

    expect(Date.now() - started).toBeLessThan(300)
  })

  it('survives a failing pass and keeps polling', async () => {
    prismaMock.webhookEvent.findMany
      .mockRejectedValueOnce(new Error('connection lost'))
      .mockResolvedValue([])

    const worker = new WebhookWorker({ idleDelayMs: 20, reclaimIntervalMs: 1_000_000 })
    worker.start()
    await new Promise((r) => setTimeout(r, 120))
    await worker.stop()

    expect(prismaMock.webhookEvent.findMany.mock.calls.length).toBeGreaterThan(1)
  })

  it('start() twice does not run two loops', async () => {
    prismaMock.webhookEvent.findMany.mockResolvedValue([])
    const worker = new WebhookWorker({ idleDelayMs: 5_000, reclaimIntervalMs: 1_000_000 })
    worker.start()
    worker.start()
    await new Promise((r) => setTimeout(r, 60))
    await worker.stop()
    expect(prismaMock.webhookEvent.findMany.mock.calls.length).toBe(1)
  })
})
