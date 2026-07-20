import { socketManager } from '../baileys/socket-manager.js'
import { env } from '../config/env.js'
import { logger } from '../config/logger.js'
import { prisma } from '../database/client.js'
import { serialQueues } from '../instances/serial-queue.js'
import { INSTANCE_STATES, type MessageStatus } from '../types/index.js'
import { WEBHOOK_STATUSES } from '../webhooks/webhook-dispatcher.js'

import { eventLoopMonitor } from './event-loop.js'

/**
 * Prometheus text exposition, hand-rolled.
 *
 * A client library would be one more dependency to keep current on a service
 * whose whole job is to stay connected; the format is a dozen lines of string
 * building and is frozen by specification, so the trade is not close. What the
 * library would buy — registry bookkeeping and metric caching — is worthless
 * here anyway, because every value below is read fresh at scrape time from
 * Postgres or from process state rather than accumulated in memory.
 */

export type MetricType = 'gauge' | 'counter'

export interface MetricSample {
  labels?: Record<string, string>
  value: number
}

export interface MetricFamily {
  name: string
  help: string
  type: MetricType
  samples: MetricSample[]
}

/**
 * Every MessageStatus, seeded to zero.
 *
 * Typed as a total Record on purpose: adding a status to the union and
 * forgetting it here is a compile error, not a metric that silently never
 * appears. It also guarantees a series exists for every status even at zero —
 * without that, an alert on `gateway_messages_total{status="FAILED"}` evaluates
 * against a missing series and never fires, which is the wrong way round.
 */
const MESSAGE_STATUS_SEED: Record<MessageStatus, number> = {
  ACCEPTED: 0,
  SENT: 0,
  SERVER_ACK: 0,
  DELIVERED: 0,
  READ: 0,
  PLAYED: 0,
  FAILED: 0,
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/** HELP text may not contain a raw newline, and a backslash must be escaped first. */
export function escapeHelp(value: string): string {
  return value.replace(/\\/g, '\\\\').replace(/\n/g, '\\n')
}

/**
 * Label values additionally escape the double quote that delimits them.
 *
 * Order matters: backslashes first, or the backslash introduced while escaping
 * a quote would itself be escaped a second time and the value would corrupt.
 */
export function escapeLabelValue(value: string): string {
  return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n')
}

/** Prometheus spells non-finite values out; a bare NaN/Infinity is a parse error. */
export function formatMetricValue(value: number): string {
  if (Number.isNaN(value)) {
    return 'NaN'
  }

  if (value === Number.POSITIVE_INFINITY) {
    return '+Inf'
  }

  if (value === Number.NEGATIVE_INFINITY) {
    return '-Inf'
  }

  // Integers stay integers; floats are capped so a lag reading does not emit
  // seventeen digits of float noise.
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(6)))
}

function renderLabels(labels: Record<string, string> | undefined): string {
  const entries = Object.entries(labels ?? {})

  if (entries.length === 0) {
    return ''
  }

  return `{${entries.map(([key, value]) => `${key}="${escapeLabelValue(value)}"`).join(',')}}`
}

/**
 * One family: exactly one HELP line, one TYPE line, then its samples. Exported
 * because the escaping rules are the part of this file most worth testing
 * directly, and no real label value in this service contains a quote.
 */
export function renderMetricFamily(family: MetricFamily): string {
  const lines = [`# HELP ${family.name} ${escapeHelp(family.help)}`, `# TYPE ${family.name} ${family.type}`]

  for (const sample of family.samples) {
    lines.push(`${family.name}${renderLabels(sample.labels)} ${formatMetricValue(sample.value)}`)
  }

  return lines.join('\n')
}

// ---------------------------------------------------------------------------
// Collection
// ---------------------------------------------------------------------------

interface GroupedCount {
  key: string
  count: number
}

function tally(rows: readonly GroupedCount[], seed: Record<string, number>): Record<string, number> {
  const counts = { ...seed }

  for (const row of rows) {
    counts[row.key] = (counts[row.key] ?? 0) + row.count
  }

  return counts
}

function labelledSamples(counts: Record<string, number>, label: string): MetricSample[] {
  return Object.entries(counts).map(([value, count]) => ({ labels: { [label]: value }, value: count }))
}

function seedFrom(values: readonly string[]): Record<string, number> {
  return Object.fromEntries(values.map((value) => [value, 0]))
}

interface DatabaseCounts {
  instances: Record<string, number>
  messages: Record<string, number>
  webhooks: Record<string, number>
}

/**
 * Three grouped counts, one round trip each — not one query per label value,
 * which would be 28 queries on every scrape.
 */
async function collectDatabaseCounts(): Promise<DatabaseCounts> {
  const [instanceRows, messageRows, webhookRows] = await Promise.all([
    prisma.instance.groupBy({ by: ['state'], _count: { _all: true } }),
    prisma.gatewayMessage.groupBy({ by: ['status'], _count: { _all: true } }),
    prisma.webhookEvent.groupBy({ by: ['status'], _count: { _all: true } }),
  ])

  return {
    instances: tally(
      instanceRows.map((row) => ({ key: String(row.state), count: row._count._all })),
      seedFrom(INSTANCE_STATES),
    ),
    messages: tally(
      messageRows.map((row) => ({ key: String(row.status), count: row._count._all })),
      MESSAGE_STATUS_SEED,
    ),
    webhooks: tally(
      webhookRows.map((row) => ({ key: String(row.status), count: row._count._all })),
      seedFrom(WEBHOOK_STATUSES),
    ),
  }
}

function buildInfoLabels(): Record<string, string> {
  let baileysPackage = 'unknown'
  let nodeId = 'unknown'

  try {
    baileysPackage = env().BAILEYS_PACKAGE
    nodeId = env().APP_NODE_ID
  } catch {
    // An unreadable environment must not cost the scrape its process metrics.
  }

  return { baileys_package: baileysPackage, node_id: nodeId, node_version: process.version }
}

/**
 * Render the full exposition.
 *
 * Note the TYPE of the three `_total` families: gauge, not counter. The names
 * are fixed by the platform's metric contract, but the values are populations —
 * rows currently in a status — and a message moving ACCEPTED to SENT makes one
 * of them go DOWN. Declaring them counters would let `rate()` read every normal
 * status transition as a counter reset and report nonsense.
 */
export async function renderMetrics(): Promise<string> {
  const families: MetricFamily[] = []

  let counts: DatabaseCounts | null = null

  try {
    counts = await collectDatabaseCounts()
  } catch (error) {
    // Deliberately omit the database-derived families rather than publish
    // zeros. A zero is indistinguishable from "the fleet is empty" and would
    // silently resolve every alert at the exact moment the database died.
    // Prometheus can express the gap with absent(); a lie has no such escape.
    logger().error({ err: error }, 'Metrics scrape could not read database counts; omitting those families.')
  }

  if (counts) {
    families.push(
      {
        name: 'gateway_instances_total',
        help: 'WhatsApp instances by lifecycle state.',
        type: 'gauge',
        samples: labelledSamples(counts.instances, 'state'),
      },
      {
        name: 'gateway_messages_total',
        help: 'Outgoing messages by current delivery status.',
        type: 'gauge',
        samples: labelledSamples(counts.messages, 'status'),
      },
      {
        name: 'gateway_webhooks_total',
        help: 'Outbound webhook events by delivery status.',
        type: 'gauge',
        samples: labelledSamples(counts.webhooks, 'status'),
      },
    )
  }

  families.push(
    {
      name: 'gateway_sockets_live',
      help: 'Live Baileys sockets owned by this node.',
      type: 'gauge',
      samples: [{ value: socketManager.liveCount() }],
    },
    {
      name: 'gateway_send_queue_depth',
      help: 'Sends queued across every per-instance serial queue on this node.',
      type: 'gauge',
      samples: [{ value: serialQueues.totalDepth() }],
    },
    {
      name: 'gateway_event_loop_lag_ms',
      help: 'Mean event loop delay in milliseconds since the last reset.',
      type: 'gauge',
      samples: [{ value: eventLoopMonitor.meanLagMs() }],
    },
    {
      name: 'gateway_process_resident_bytes',
      help: 'Resident set size of this gateway process in bytes.',
      type: 'gauge',
      samples: [{ value: process.memoryUsage().rss }],
    },
    {
      name: 'gateway_uptime_seconds',
      help: 'Seconds since this gateway process started.',
      type: 'gauge',
      samples: [{ value: Math.round(process.uptime()) }],
    },
    {
      // The value carries no information; the labels do. This is the standard
      // build-info idiom, and it is what lets a query join a metric to the
      // Baileys implementation that produced it after a package switch.
      name: 'gateway_build_info',
      help: 'Build and runtime identity of this gateway process. Always 1.',
      type: 'gauge',
      samples: [{ labels: buildInfoLabels(), value: 1 }],
    },
  )

  // Trailing newline: a body that ends mid-line is rejected by some scrapers.
  return `${families.map(renderMetricFamily).join('\n')}\n`
}
