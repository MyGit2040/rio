import pino from 'pino'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { setEnvForTesting } from '../../src/config/env.js'
import { setLoggerForTesting } from '../../src/config/logger.js'
import type { HealthLevel, HealthReport } from '../../src/types/index.js'
import { testEnv } from '../helpers/env-fixture.js'

const { pruneNonces, pruneMedia, enqueueWebhook, buildHealthReport } = vi.hoisted(() => ({
  pruneNonces: vi.fn(),
  pruneMedia: vi.fn(),
  enqueueWebhook: vi.fn(),
  buildHealthReport: vi.fn(),
}))

vi.mock('../../src/security/nonce-store.js', () => ({ pruneNonces }))
vi.mock('../../src/baileys/event-handler.js', () => ({ pruneMedia }))
vi.mock('../../src/webhooks/webhook-dispatcher.js', () => ({ enqueueWebhook }))
vi.mock('../../src/monitoring/health.js', () => ({ buildHealthReport }))

const { MaintenanceWorker } = await import('../../src/jobs/maintenance.worker.js')

function report(level: HealthLevel): HealthReport {
  return {
    level,
    nodeId: 'test-node',
    checks: [
      { name: 'database', level: 'ok' },
      { name: 'redis', level },
    ],
    checkedAt: new Date().toISOString(),
  }
}

beforeEach(() => {
  setEnvForTesting(testEnv({ REQUEST_MAX_SKEW_SECONDS: '300' }))
  setLoggerForTesting(pino({ level: 'silent' }))
  vi.clearAllMocks()
  pruneNonces.mockResolvedValue(0)
  pruneMedia.mockResolvedValue(0)
  enqueueWebhook.mockResolvedValue('event-id')
  buildHealthReport.mockResolvedValue(report('ok'))
})

describe('MaintenanceWorker housekeeping', () => {
  it('prunes nonces at twice the accepted clock skew', async () => {
    await new MaintenanceWorker().runOnce()

    // Anything shorter would delete a nonce whose signature is still inside the
    // replay window, quietly re-opening the replay it exists to prevent.
    expect(pruneNonces).toHaveBeenCalledWith(600)
  })

  it('prunes expired media', async () => {
    await new MaintenanceWorker().runOnce()

    expect(pruneMedia).toHaveBeenCalledTimes(1)
  })

  it('keeps running the remaining tasks when one throws', async () => {
    pruneNonces.mockRejectedValue(new Error('database gone'))

    await expect(new MaintenanceWorker().runOnce()).resolves.toBeUndefined()

    // The health check is how anyone finds out the prune failed, so it must not
    // be the thing the failure takes down.
    expect(pruneMedia).toHaveBeenCalledTimes(1)
    expect(buildHealthReport).toHaveBeenCalledTimes(1)
  })
})

describe('MaintenanceWorker health alerting', () => {
  it('emits gateway.health_warning on the transition into a degraded state', async () => {
    buildHealthReport.mockResolvedValue(report('warning'))

    await new MaintenanceWorker().runOnce()

    expect(enqueueWebhook).toHaveBeenCalledTimes(1)
    const [payload] = enqueueWebhook.mock.calls[0] as [Record<string, unknown>]
    expect(payload.eventType).toBe('gateway.health_warning')
    expect((payload.data as Record<string, unknown>).level).toBe('warning')
    expect((payload.data as Record<string, unknown>).previous_level).toBe('ok')
  })

  it('stays silent while the same problem persists', async () => {
    buildHealthReport.mockResolvedValue(report('warning'))
    const worker = new MaintenanceWorker()

    await worker.runOnce()
    await worker.runOnce()
    await worker.runOnce()

    // One event for the incident, not one every five minutes for its duration —
    // otherwise the events that matter are buried under copies of the one
    // already being worked on.
    expect(enqueueWebhook).toHaveBeenCalledTimes(1)
  })

  it('emits again when a warning escalates to critical', async () => {
    const worker = new MaintenanceWorker()

    buildHealthReport.mockResolvedValue(report('warning'))
    await worker.runOnce()

    buildHealthReport.mockResolvedValue(report('critical'))
    await worker.runOnce()

    expect(enqueueWebhook).toHaveBeenCalledTimes(2)
    const [second] = enqueueWebhook.mock.calls[1] as [Record<string, unknown>]
    expect((second.data as Record<string, unknown>).previous_level).toBe('warning')
    expect((second.data as Record<string, unknown>).level).toBe('critical')
  })

  it('does not emit on recovery, and re-arms for the next degradation', async () => {
    const worker = new MaintenanceWorker()

    buildHealthReport.mockResolvedValue(report('critical'))
    await worker.runOnce()

    buildHealthReport.mockResolvedValue(report('ok'))
    await worker.runOnce()

    // No gateway.health_ok exists in the webhook contract, so recovery is
    // logged rather than invented as an event Laravel cannot handle.
    expect(enqueueWebhook).toHaveBeenCalledTimes(1)

    buildHealthReport.mockResolvedValue(report('warning'))
    await worker.runOnce()

    expect(enqueueWebhook).toHaveBeenCalledTimes(2)
  })

  it('sends only the failing checks, not the whole report', async () => {
    buildHealthReport.mockResolvedValue(report('critical'))

    await new MaintenanceWorker().runOnce()

    const [payload] = enqueueWebhook.mock.calls[0] as [Record<string, unknown>]
    const failing = (payload.data as { failing_checks: { name: string }[] }).failing_checks
    expect(failing.map((check) => check.name)).toEqual(['redis'])
  })

  it('does not re-fire the same transition after a failed enqueue', async () => {
    buildHealthReport.mockResolvedValue(report('critical'))
    enqueueWebhook.mockRejectedValue(new Error('database gone'))

    const worker = new MaintenanceWorker()
    await worker.runOnce()
    await worker.runOnce()

    expect(enqueueWebhook).toHaveBeenCalledTimes(1)
  })
})

describe('MaintenanceWorker lifecycle', () => {
  it('is idempotent on start and waits for an in-flight pass on stop', async () => {
    let release: () => void = () => {}
    pruneMedia.mockImplementation(
      () =>
        new Promise<number>((resolve) => {
          release = () => resolve(0)
        }),
    )

    const worker = new MaintenanceWorker({ intervalMs: 10_000, runOnStart: true })
    worker.start()
    worker.start()

    // Wait for the pass to reach the task that is being held open, rather than
    // guessing at a number of microtasks.
    await vi.waitFor(() => {
      expect(pruneMedia).toHaveBeenCalledTimes(1)
    })

    const stopped = worker.stop()
    let settled = false
    void stopped.then(() => {
      settled = true
    })

    await Promise.resolve()
    // stop() must not resolve while a prune is half-finished.
    expect(settled).toBe(false)

    release()
    await stopped
    expect(settled).toBe(true)
    // Two start() calls, one pass: the second start is a no-op.
    expect(pruneMedia).toHaveBeenCalledTimes(1)
  })
})
