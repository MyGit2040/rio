import pino from 'pino'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { setEnvForTesting } from '../../src/config/env.js'
import { setLoggerForTesting } from '../../src/config/logger.js'
import type { HealthCheck } from '../../src/types/index.js'
import { testEnv } from '../helpers/env-fixture.js'

/**
 * No live services. Postgres is a mock, Redis is a two-line double, and the
 * socket manager is stubbed — a health test that needed the very dependencies
 * it reports on could only ever run on a developer's machine.
 */

const { prismaMock, databaseHealthy } = vi.hoisted(() => ({
  prismaMock: {
    instance: { count: vi.fn() },
    webhookEvent: { count: vi.fn() },
    authCredential: { count: vi.fn() },
    gatewayMessage: { aggregate: vi.fn() },
  },
  databaseHealthy: vi.fn(),
}))

vi.mock('../../src/database/client.js', () => ({ prisma: prismaMock, databaseHealthy }))

vi.mock('../../src/baileys/socket-manager.js', () => ({
  socketManager: { liveCount: () => 3, isLive: () => true },
}))

vi.mock('../../src/instances/instance-lock.js', () => ({
  // A real connection attempt would be a test that hangs in CI. Every test
  // installs a probe double, so reaching this is itself the failure.
  createRedis: () => {
    throw new Error('createRedis must not be reached: install a probe with setRedisProbeForTesting.')
  },
}))

const {
  buildHealthReport,
  isShuttingDown,
  livenessOk,
  readinessOk,
  setRedisProbeForTesting,
  setShuttingDown,
  worstLevel,
} = await import('../../src/monitoring/health.js')

function find(checks: HealthCheck[], name: string): HealthCheck {
  const check = checks.find((candidate) => candidate.name === name)

  if (!check) {
    throw new Error(`Expected a health check named '${name}', got: ${checks.map((c) => c.name).join(', ')}`)
  }

  return check
}

/** Every dependency reachable and every count clean. */
function healthyFleet(): void {
  databaseHealthy.mockResolvedValue(true)
  setRedisProbeForTesting({ ping: async () => 'PONG' })
  prismaMock.instance.count.mockResolvedValue(0)
  prismaMock.webhookEvent.count.mockResolvedValue(0)
  prismaMock.authCredential.count.mockResolvedValue(0)
  prismaMock.gatewayMessage.aggregate.mockResolvedValue({
    _max: { sentAt: null, serverAckAt: null, deliveredAt: null, readAt: null },
  })
}

beforeEach(() => {
  setEnvForTesting(testEnv())
  setLoggerForTesting(pino({ level: 'silent' }))
  vi.clearAllMocks()
  setShuttingDown(false)
  healthyFleet()
})

describe('worstLevel', () => {
  it('returns the worst level present, not the most common one', () => {
    expect(
      worstLevel([
        { name: 'a', level: 'ok' },
        { name: 'b', level: 'ok' },
        { name: 'c', level: 'warning' },
        { name: 'd', level: 'ok' },
      ]),
    ).toBe('warning')
  })

  it('lets a single critical outrank any number of warnings', () => {
    expect(
      worstLevel([
        { name: 'a', level: 'warning' },
        { name: 'b', level: 'critical' },
        { name: 'c', level: 'warning' },
      ]),
    ).toBe('critical')
  })

  it('is ok when there is nothing to report', () => {
    expect(worstLevel([])).toBe('ok')
  })
})

describe('buildHealthReport', () => {
  it('reports ok when every dependency is reachable and every count is clean', async () => {
    const report = await buildHealthReport()

    expect(report.level).toBe('ok')
    expect(report.nodeId).toBe('test-node')
    expect(find(report.checks, 'database').level).toBe('ok')
    expect(find(report.checks, 'redis').level).toBe('ok')
    expect(find(report.checks, 'sockets_live').value).toBe(3)
    expect(Date.parse(report.checkedAt)).not.toBeNaN()
  })

  it('rolls up to the worst level across all checks', async () => {
    // One warning (dead-lettered webhooks) and one critical (Redis unreachable).
    prismaMock.webhookEvent.count.mockImplementation(async (args: { where: { status?: unknown } }) =>
      args.where.status === 'DEAD_LETTER' ? 4 : 0,
    )
    setRedisProbeForTesting({
      ping: async () => {
        throw new Error('ECONNREFUSED')
      },
    })

    const report = await buildHealthReport()

    expect(find(report.checks, 'webhooks_dead_letter').level).toBe('warning')
    expect(find(report.checks, 'redis').level).toBe('critical')
    expect(report.level).toBe('critical')
  })

  it('stays at warning when nothing is critical', async () => {
    prismaMock.instance.count.mockImplementation(async (args: { where: { state?: unknown } }) =>
      args.where.state === 'ERROR' ? 2 : 0,
    )

    const report = await buildHealthReport()

    expect(find(report.checks, 'instances_errored').level).toBe('warning')
    expect(find(report.checks, 'database').level).toBe('ok')
    expect(report.level).toBe('warning')
  })

  it('warns when a READY instance has stopped writing credentials', async () => {
    prismaMock.authCredential.count.mockResolvedValue(1)

    const report = await buildHealthReport()

    const stale = find(report.checks, 'credential_writes_stale')
    expect(stale.level).toBe('warning')
    expect(stale.value).toBe(1)
    expect(report.level).toBe('warning')
  })

  it('warns once the pending webhook backlog passes the threshold', async () => {
    prismaMock.webhookEvent.count.mockImplementation(async (args: { where: { status?: unknown } }) =>
      args.where.status === 'DEAD_LETTER' ? 0 : 501,
    )

    const report = await buildHealthReport()

    expect(find(report.checks, 'webhooks_pending').level).toBe('warning')
  })

  it('reports a throwing check as critical without losing the rest of the report', async () => {
    prismaMock.instance.count.mockImplementation(async (args: { where: { state?: unknown } }) => {
      if (args.where.state === 'RECONNECT_WAIT') {
        throw new Error('connection reset by peer')
      }

      return 0
    })

    const report = await buildHealthReport()

    const failed = find(report.checks, 'instance_states')
    expect(failed.level).toBe('critical')
    expect(failed.detail).toContain('connection reset by peer')

    // The point of per-check isolation: the verdicts an operator actually needs
    // survive a neighbouring check blowing up.
    expect(find(report.checks, 'database').level).toBe('ok')
    expect(find(report.checks, 'redis').level).toBe('ok')
    expect(find(report.checks, 'sockets_live').value).toBe(3)
    expect(find(report.checks, 'process_uptime').level).toBe('ok')
    expect(report.level).toBe('critical')
  })

  it('surfaces the last send and the last inbound acknowledgement', async () => {
    const sent = new Date(Date.now() - 30_000)
    const read = new Date(Date.now() - 5_000)

    prismaMock.gatewayMessage.aggregate.mockResolvedValue({
      _max: { sentAt: sent, serverAckAt: null, deliveredAt: null, readAt: read },
    })

    const report = await buildHealthReport()

    expect(find(report.checks, 'last_successful_send').value).toBe(sent.toISOString())
    // The newest of the three acknowledgement columns wins.
    expect(find(report.checks, 'last_received_event').value).toBe(read.toISOString())
    // Silence is never an alert: campaign scheduling belongs to Laravel.
    expect(report.level).toBe('ok')
  })

  it('omits the timestamp rather than reporting the epoch when nothing has been sent', async () => {
    const report = await buildHealthReport()

    expect(find(report.checks, 'last_successful_send').value).toBeUndefined()
  })
})

describe('livenessOk', () => {
  it('stays true when the database is down', async () => {
    databaseHealthy.mockResolvedValue(false)

    // The whole reason liveness excludes dependencies: a false here would have
    // the orchestrator kill a container that is merely waiting on Postgres,
    // taking every WhatsApp session it owns down with it.
    expect(livenessOk()).toBe(true)
    expect(await readinessOk()).toBe(false)
  })

  it('stays true when Redis is down', async () => {
    setRedisProbeForTesting({
      ping: async () => {
        throw new Error('ECONNREFUSED')
      },
    })

    expect(livenessOk()).toBe(true)
    expect(await readinessOk()).toBe(false)
  })

  it('stays true while shutting down, so a draining process is not killed mid-flush', () => {
    setShuttingDown(true)

    expect(livenessOk()).toBe(true)
  })

  it('never touches the database or Redis', async () => {
    livenessOk()

    expect(databaseHealthy).not.toHaveBeenCalled()
    expect(prismaMock.instance.count).not.toHaveBeenCalled()
  })
})

describe('readinessOk', () => {
  it('is true when both dependencies answer', async () => {
    expect(await readinessOk()).toBe(true)
  })

  it('goes false while shutting down even though the dependencies are fine', async () => {
    setShuttingDown(true)

    expect(isShuttingDown()).toBe(true)
    expect(await readinessOk()).toBe(false)
  })

  it('is false when Redis answers with something other than PONG', async () => {
    setRedisProbeForTesting({ ping: async () => 'LOADING' })

    expect(await readinessOk()).toBe(false)
  })
})
