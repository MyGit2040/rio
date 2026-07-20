import { socketManager } from '../baileys/socket-manager.js'
import { env } from '../config/env.js'
import { logger } from '../config/logger.js'
import { databaseHealthy, prisma } from '../database/client.js'
import { createRedis } from '../instances/instance-lock.js'
import type { HealthCheck, HealthLevel, HealthReport } from '../types/index.js'

import { eventLoopMonitor } from './event-loop.js'

/**
 * Health, in three deliberately different shapes.
 *
 *   livenessOk       — is this process alive? Nothing else.
 *   readinessOk      — should this process receive traffic right now?
 *   buildHealthReport— what is the operator supposed to look at?
 *
 * Collapsing these into one endpoint is the classic mistake, and an expensive
 * one here: a liveness probe that consults Postgres turns a thirty-second
 * database blip into a container restart, and restarting this container drops
 * every WhatsApp socket it owns. Those sessions then reconnect en masse, which
 * looks to WhatsApp exactly like the abuse pattern we spend the rest of this
 * codebase avoiding. Liveness therefore answers from process state only.
 */

// ---------------------------------------------------------------------------
// Thresholds
//
// Exported so alert rules and runbooks can cite the same numbers the code uses
// rather than a copy that drifts.
// ---------------------------------------------------------------------------

/** RECONNECT_WAIT longer than this means the backoff schedule is not progressing. */
export const RECONNECT_WAIT_STUCK_MINUTES = 15

/** Undelivered webhook backlog that suggests Laravel is not keeping up. */
export const WEBHOOK_PENDING_WARNING = 500

/** An auth store that has not written in this long is heading for a forced re-scan. */
export const CREDENTIAL_STALE_HOURS = 24

/** Sustained loop delay above this is felt as latency by every caller. */
export const EVENT_LOOP_LAG_WARNING_MS = 200

const LEVEL_RANK: Record<HealthLevel, number> = { ok: 0, warning: 1, critical: 2 }

// ---------------------------------------------------------------------------
// Process state
// ---------------------------------------------------------------------------

let shuttingDown = false

/**
 * Flipped by the shutdown handler before sockets start draining.
 *
 * Readiness goes false first so the load balancer stops sending work while the
 * process is still perfectly capable of finishing what it already has.
 */
export function setShuttingDown(value: boolean): void {
  shuttingDown = value
}

export function isShuttingDown(): boolean {
  return shuttingDown
}

/**
 * Process-level liveness. MUST NOT touch Postgres or Redis.
 *
 * Returning true unconditionally is the point, not an oversight: reaching this
 * line proves the process is up, the event loop is turning and the HTTP layer
 * can answer — which is the entire question a liveness probe asks. A dependency
 * outage is a readiness concern; answering it here would have the orchestrator
 * kill a container that was merely waiting, converting a brief outage of one
 * dependency into an outage of every session this node owns.
 *
 * Note it stays true during shutdown as well. A process draining sockets is
 * alive and must be allowed to finish; failing liveness mid-drain earns a
 * SIGKILL and loses the credential flush.
 */
export function livenessOk(): boolean {
  return true
}

// ---------------------------------------------------------------------------
// Redis probe
// ---------------------------------------------------------------------------

/** The one Redis capability health needs. Narrow so tests can supply a double. */
export interface RedisPingable {
  ping(): Promise<string>
}

let probeClient: RedisPingable | null = null

/**
 * A cached connection, not a fresh one per probe: readiness is polled every few
 * seconds, and a connection per poll would exhaust Redis client slots faster
 * than any real workload.
 */
function redisProbe(): RedisPingable {
  probeClient ??= createRedis()

  return probeClient
}

export function setRedisProbeForTesting(client: RedisPingable | null): void {
  probeClient = client
}

export async function redisHealthy(): Promise<boolean> {
  try {
    return (await redisProbe().ping()) === 'PONG'
  } catch {
    return false
  }
}

/**
 * Should this node be sent traffic?
 *
 * Unlike liveness this DOES consult dependencies: a gateway that cannot reach
 * Postgres cannot persist a message, and one that cannot reach Redis cannot
 * prove socket ownership, so accepting a send would either lose it or risk a
 * split session.
 */
export async function readinessOk(): Promise<boolean> {
  if (shuttingDown) {
    return false
  }

  const [database, redis] = await Promise.all([databaseHealthy(), redisHealthy()])

  return database && redis
}

// ---------------------------------------------------------------------------
// Full report
// ---------------------------------------------------------------------------

export function worstLevel(checks: readonly HealthCheck[]): HealthLevel {
  return checks.reduce<HealthLevel>(
    (worst, check) => (LEVEL_RANK[check.level] > LEVEL_RANK[worst] ? check.level : worst),
    'ok',
  )
}

function describe(error: unknown): string {
  return error instanceof Error ? `${error.name}: ${error.message}` : String(error)
}

/**
 * Per-check isolation.
 *
 * A health report exists to be readable when things are broken, so one failing
 * probe must not take the report with it. If a stuck query in the webhook check
 * could throw the whole endpoint into a 500, an operator loses the database and
 * Redis verdicts too — the two facts they most needed. A thrown check reports
 * itself as critical and every other check still renders.
 */
async function runCheck(name: string, run: () => Promise<HealthCheck[]>): Promise<HealthCheck[]> {
  try {
    return await run()
  } catch (error) {
    // Production runs at LOG_LEVEL=error, so anything quieter is invisible.
    logger().error({ err: error, check: name }, 'Health check threw; reporting it as critical.')

    return [{ name, level: 'critical', detail: `Check failed: ${describe(error)}` }]
  }
}

function nodeId(): string {
  try {
    return env().APP_NODE_ID
  } catch {
    // A report is still useful without the node id; refusing to render one
    // because the environment is unreadable would hide that very fact.
    return 'unknown'
  }
}

function ageSeconds(from: Date): number {
  return Math.max(0, Math.round((Date.now() - from.getTime()) / 1000))
}

function newest(dates: readonly (Date | null | undefined)[]): Date | null {
  let latest: Date | null = null

  for (const date of dates) {
    if (date && (!latest || date.getTime() > latest.getTime())) {
      latest = date
    }
  }

  return latest
}

async function checkDatabase(): Promise<HealthCheck[]> {
  const ok = await databaseHealthy()

  return [
    {
      name: 'database',
      level: ok ? 'ok' : 'critical',
      detail: ok ? 'Reachable.' : 'SELECT 1 failed; the gateway cannot persist messages or auth state.',
    },
  ]
}

async function checkRedis(): Promise<HealthCheck[]> {
  const ok = await redisHealthy()

  return [
    {
      name: 'redis',
      level: ok ? 'ok' : 'critical',
      detail: ok ? 'Reachable.' : 'PING failed; socket ownership leases cannot be renewed or proved.',
    },
  ]
}

async function checkSockets(): Promise<HealthCheck[]> {
  const live = socketManager.liveCount()
  const ready = await prisma.instance.count({ where: { state: 'READY' } })

  // Deliberately not compared against each other. READY counts the whole fleet
  // while liveCount is this node only, so on a multi-node deployment live < ready
  // is the normal state, not a fault.
  return [
    { name: 'sockets_live', level: 'ok', value: live, detail: 'Live Baileys sockets owned by this node.' },
    { name: 'instances_ready', level: 'ok', value: ready, detail: 'Instances in READY across the fleet.' },
  ]
}

async function checkInstanceStates(): Promise<HealthCheck[]> {
  const stuckSince = new Date(Date.now() - RECONNECT_WAIT_STUCK_MINUTES * 60_000)

  const [stuck, errored] = await Promise.all([
    prisma.instance.count({ where: { state: 'RECONNECT_WAIT', updatedAt: { lt: stuckSince } } }),
    prisma.instance.count({ where: { state: 'ERROR' } }),
  ])

  return [
    {
      name: 'instances_reconnect_stuck',
      // Warning, not critical: reconnects are expected churn. What is not
      // expected is one that never advances — that means the backoff schedule
      // stopped firing, and the instance is silently offline rather than retrying.
      level: stuck > 0 ? 'warning' : 'ok',
      value: stuck,
      detail:
        stuck > 0
          ? `${stuck} instance(s) in RECONNECT_WAIT for over ${RECONNECT_WAIT_STUCK_MINUTES} minutes.`
          : 'No stalled reconnects.',
    },
    {
      name: 'instances_errored',
      // ERROR is terminal until a human acts, so it never clears on its own.
      level: errored > 0 ? 'warning' : 'ok',
      value: errored,
      detail: errored > 0 ? `${errored} instance(s) in ERROR require manual action.` : 'No instances in ERROR.',
    },
  ]
}

async function checkWebhooks(): Promise<HealthCheck[]> {
  const [pending, deadLettered] = await Promise.all([
    // RETRY_WAIT counts as backlog: from Laravel's side an event waiting on a
    // retry is just as undelivered as one that has never been tried.
    prisma.webhookEvent.count({ where: { status: { in: ['PENDING', 'RETRY_WAIT'] } } }),
    prisma.webhookEvent.count({ where: { status: 'DEAD_LETTER' } }),
  ])

  return [
    {
      name: 'webhooks_pending',
      level: pending > WEBHOOK_PENDING_WARNING ? 'warning' : 'ok',
      value: pending,
      detail:
        pending > WEBHOOK_PENDING_WARNING
          ? `${pending} events awaiting delivery (threshold ${WEBHOOK_PENDING_WARNING}); Laravel may be slow or down.`
          : `${pending} events awaiting delivery.`,
    },
    {
      // Any dead letter is an event Laravel will never see unless replayed, so
      // the threshold is one.
      name: 'webhooks_dead_letter',
      level: deadLettered > 0 ? 'warning' : 'ok',
      value: deadLettered,
      detail:
        deadLettered > 0
          ? `${deadLettered} event(s) exhausted every delivery attempt and need replaying.`
          : 'No dead-lettered events.',
    },
  ]
}

async function checkCredentialWrites(): Promise<HealthCheck[]> {
  const cutoff = new Date(Date.now() - CREDENTIAL_STALE_HOURS * 3_600_000)

  // One relation-filtered count rather than lastCredentialWriteAt() per
  // instance: this runs on a probe path, and N round trips per scrape would
  // make the health endpoint itself a load problem on a large fleet.
  const stale = await prisma.authCredential.count({
    where: { lastWriteAt: { lt: cutoff }, instance: { state: 'READY' } },
  })

  return [
    {
      name: 'credential_writes_stale',
      // Baileys mutates auth state during ordinary traffic, so a READY session
      // that has not written in a day is not idle — its writes are failing.
      // This is the earliest available warning that sessions are about to start
      // demanding a QR re-scan, which is why it is surfaced before anything
      // visibly breaks.
      level: stale > 0 ? 'warning' : 'ok',
      value: stale,
      detail:
        stale > 0
          ? `${stale} READY instance(s) have not persisted credentials in over ${CREDENTIAL_STALE_HOURS}h; ` +
            'auth writes are probably failing and a re-link may follow.'
          : 'All READY instances have written credentials recently.',
    },
  ]
}

function checkEventLoop(): HealthCheck[] {
  const mean = eventLoopMonitor.meanLagMs()
  const p99 = eventLoopMonitor.p99LagMs()

  // Alert on p99, report mean. A mean stays comfortable while a handful of
  // multi-second stalls make every send time out, and it is the stalls callers
  // actually experience.
  return [
    {
      name: 'event_loop_lag',
      level: p99 > EVENT_LOOP_LAG_WARNING_MS ? 'warning' : 'ok',
      value: Math.round(p99 * 100) / 100,
      detail: `p99 ${p99.toFixed(1)}ms, mean ${mean.toFixed(1)}ms (threshold ${EVENT_LOOP_LAG_WARNING_MS}ms).`,
    },
  ]
}

function checkProcess(): HealthCheck[] {
  const { rss } = process.memoryUsage()
  const uptime = Math.round(process.uptime())

  // Both informational. There is no portable "too much memory" here — the real
  // ceiling is the container limit, which this process cannot read reliably —
  // so the number is published for the alerting system to threshold and never
  // guessed at locally.
  return [
    {
      name: 'process_memory_rss',
      level: 'ok',
      value: rss,
      detail: `${Math.round(rss / 1024 / 1024)} MiB resident.`,
    },
    { name: 'process_uptime', level: 'ok', value: uptime, detail: `Up ${uptime}s.` },
  ]
}

async function checkTraffic(): Promise<HealthCheck[]> {
  // One aggregate for all four maxima. Four findFirst calls would return the
  // same answer for four times the cost on an endpoint that is polled.
  const aggregate = await prisma.gatewayMessage.aggregate({
    _max: { sentAt: true, serverAckAt: true, deliveredAt: true, readAt: true },
  })

  const lastSend = aggregate._max.sentAt ?? null

  // "Received" here means the newest acknowledgement WhatsApp sent back about
  // one of our messages. Inbound message bodies are never persisted — they are
  // normalised and forwarded straight to Laravel — so GatewayMessage's ack
  // columns are the gateway's own record of traffic arriving from WhatsApp.
  const lastInbound = newest([aggregate._max.serverAckAt, aggregate._max.deliveredAt, aggregate._max.readAt])

  // Both stay 'ok' whatever the age. Quiet is not broken: campaign scheduling
  // belongs to Laravel, and a gateway with nothing to send overnight is working
  // exactly as designed. Escalating on silence would page someone every night.
  // `value` is omitted rather than zeroed when nothing has happened yet — a 0
  // timestamp would read as 1970 on any dashboard that parsed it.
  return [
    {
      name: 'last_successful_send',
      level: 'ok',
      ...(lastSend ? { value: lastSend.toISOString() } : {}),
      detail: lastSend ? `${ageSeconds(lastSend)}s ago.` : 'No message has been sent yet.',
    },
    {
      name: 'last_received_event',
      level: 'ok',
      ...(lastInbound ? { value: lastInbound.toISOString() } : {}),
      detail: lastInbound ? `${ageSeconds(lastInbound)}s ago.` : 'No inbound acknowledgement recorded yet.',
    },
  ]
}

/**
 * The operator-facing view. Every check runs, every failure is contained, and
 * the report's level is the worst individual verdict — a report that averaged
 * its checks would let one critical failure hide behind a dozen healthy ones.
 */
export async function buildHealthReport(): Promise<HealthReport> {
  const groups = await Promise.all([
    runCheck('database', checkDatabase),
    runCheck('redis', checkRedis),
    runCheck('sockets', checkSockets),
    runCheck('instance_states', checkInstanceStates),
    runCheck('webhooks', checkWebhooks),
    runCheck('credential_writes', checkCredentialWrites),
    runCheck('event_loop', async () => checkEventLoop()),
    runCheck('process', async () => checkProcess()),
    runCheck('traffic', checkTraffic),
  ])

  const checks = groups.flat()

  return {
    level: worstLevel(checks),
    nodeId: nodeId(),
    checks,
    checkedAt: new Date().toISOString(),
  }
}
