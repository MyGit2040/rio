import pino from 'pino'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { setEnvForTesting } from '../../src/config/env.js'
import { setLoggerForTesting } from '../../src/config/logger.js'
import { testEnv } from '../helpers/env-fixture.js'

const { prismaMock } = vi.hoisted(() => ({
  prismaMock: {
    instance: { groupBy: vi.fn() },
    gatewayMessage: { groupBy: vi.fn() },
    webhookEvent: { groupBy: vi.fn() },
  },
}))

vi.mock('../../src/database/client.js', () => ({ prisma: prismaMock, databaseHealthy: vi.fn() }))

vi.mock('../../src/baileys/socket-manager.js', () => ({
  socketManager: { liveCount: () => 2, isLive: () => true },
}))

const { escapeLabelValue, formatMetricValue, renderMetricFamily, renderMetrics } = await import(
  '../../src/monitoring/metrics.js'
)

// ---------------------------------------------------------------------------
// A deliberately strict parser.
//
// Asserting on substrings would pass on output no scraper could read. This
// walks the exposition the way Prometheus does — HELP and TYPE must precede
// their samples, names must be well formed, and label values must unescape
// back to what went in.
// ---------------------------------------------------------------------------

const METRIC_NAME = /^[a-z_][a-z0-9_]*$/
const SAMPLE_LINE = /^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{.*\})? (-?[0-9][0-9.eE+-]*|NaN|\+Inf|-Inf)$/
const LABEL_PAIR = /([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\]|\\.)*)"/g

interface ParsedSample {
  name: string
  labels: Record<string, string>
  value: string
}

interface ParsedExposition {
  helps: Map<string, number>
  types: Map<string, string>
  typeCounts: Map<string, number>
  samples: ParsedSample[]
}

/** Single pass, mirroring the escape order: `\\`, `\"` and `\n` all unwind here. */
function unescapeLabelValue(raw: string): string {
  return raw.replace(/\\(.)/g, (_match, character: string) => (character === 'n' ? '\n' : character))
}

function parseLabels(raw: string | undefined): Record<string, string> {
  const labels: Record<string, string> = {}

  if (!raw) {
    return labels
  }

  const inner = raw.slice(1, -1)
  let match: RegExpExecArray | null

  LABEL_PAIR.lastIndex = 0

  while ((match = LABEL_PAIR.exec(inner)) !== null) {
    labels[match[1] as string] = unescapeLabelValue(match[2] as string)
  }

  return labels
}

function parseExposition(text: string): ParsedExposition {
  const helps = new Map<string, number>()
  const types = new Map<string, string>()
  const typeCounts = new Map<string, number>()
  const samples: ParsedSample[] = []

  for (const [index, line] of text.split('\n').entries()) {
    if (line === '') {
      continue
    }

    if (line.startsWith('# HELP ')) {
      const [name] = line.slice('# HELP '.length).split(' ', 1)
      helps.set(name as string, (helps.get(name as string) ?? 0) + 1)
      continue
    }

    if (line.startsWith('# TYPE ')) {
      const parts = line.slice('# TYPE '.length).split(' ')
      const name = parts[0] as string
      types.set(name, parts[1] as string)
      typeCounts.set(name, (typeCounts.get(name) ?? 0) + 1)
      continue
    }

    const match = SAMPLE_LINE.exec(line)

    if (!match) {
      throw new Error(`Line ${index + 1} is not valid Prometheus exposition: ${JSON.stringify(line)}`)
    }

    samples.push({
      name: match[1] as string,
      labels: parseLabels(match[2]),
      value: match[3] as string,
    })
  }

  return { helps, types, typeCounts, samples }
}

function samplesFor(parsed: ParsedExposition, name: string): ParsedSample[] {
  return parsed.samples.filter((sample) => sample.name === name)
}

beforeEach(() => {
  setEnvForTesting(testEnv({ BAILEYS_PACKAGE: 'v7rc' }))
  setLoggerForTesting(pino({ level: 'silent' }))
  vi.clearAllMocks()

  prismaMock.instance.groupBy.mockResolvedValue([
    { state: 'READY', _count: { _all: 4 } },
    { state: 'RECONNECT_WAIT', _count: { _all: 1 } },
  ])
  prismaMock.gatewayMessage.groupBy.mockResolvedValue([
    { status: 'SENT', _count: { _all: 12 } },
    { status: 'DELIVERED', _count: { _all: 7 } },
  ])
  prismaMock.webhookEvent.groupBy.mockResolvedValue([
    { status: 'DELIVERED', _count: { _all: 30 } },
    { status: 'DEAD_LETTER', _count: { _all: 2 } },
  ])
})

describe('renderMetrics', () => {
  it('produces output that parses as valid Prometheus exposition', async () => {
    const parsed = parseExposition(await renderMetrics())

    expect(parsed.samples.length).toBeGreaterThan(0)

    for (const sample of parsed.samples) {
      expect(sample.name).toMatch(METRIC_NAME)
      // A sample with no HELP/TYPE ahead of it is an untyped series.
      expect(parsed.helps.get(sample.name)).toBe(1)
      expect(parsed.typeCounts.get(sample.name)).toBe(1)
    }
  })

  it('emits exactly one HELP and one TYPE per metric family', async () => {
    const parsed = parseExposition(await renderMetrics())

    for (const [name, count] of parsed.helps) {
      expect(count, `duplicate HELP for ${name}`).toBe(1)
    }

    for (const [name, count] of parsed.typeCounts) {
      expect(count, `duplicate TYPE for ${name}`).toBe(1)
    }

    expect([...parsed.helps.keys()].sort()).toEqual([...parsed.types.keys()].sort())
  })

  it('ends with a newline', async () => {
    expect(await renderMetrics()).toMatch(/\n$/)
  })

  it('exposes every family the platform contract names', async () => {
    const parsed = parseExposition(await renderMetrics())

    for (const family of [
      'gateway_instances_total',
      'gateway_sockets_live',
      'gateway_messages_total',
      'gateway_webhooks_total',
      'gateway_send_queue_depth',
      'gateway_event_loop_lag_ms',
      'gateway_process_resident_bytes',
      'gateway_uptime_seconds',
      'gateway_build_info',
    ]) {
      expect(parsed.types.has(family), `missing family ${family}`).toBe(true)
    }
  })

  it('labels grouped counts and seeds the statuses that had no rows', async () => {
    const parsed = parseExposition(await renderMetrics())

    const messages = samplesFor(parsed, 'gateway_messages_total')
    const byStatus = Object.fromEntries(messages.map((sample) => [sample.labels.status, sample.value]))

    expect(byStatus.SENT).toBe('12')
    expect(byStatus.DELIVERED).toBe('7')
    // Seeded to zero rather than omitted, so an alert on FAILED has a series to
    // evaluate against before the first failure ever happens.
    expect(byStatus.FAILED).toBe('0')
    expect(messages).toHaveLength(7)

    const instances = samplesFor(parsed, 'gateway_instances_total')
    const states = Object.fromEntries(instances.map((sample) => [sample.labels.state, sample.value]))
    expect(states.READY).toBe('4')
    expect(states.RECONNECT_WAIT).toBe('1')
    expect(states.LOGGED_OUT).toBe('0')

    const webhooks = samplesFor(parsed, 'gateway_webhooks_total')
    const webhookStatuses = Object.fromEntries(webhooks.map((sample) => [sample.labels.status, sample.value]))
    expect(webhookStatuses.DEAD_LETTER).toBe('2')
    expect(webhookStatuses.PENDING).toBe('0')
  })

  it('reports population families as gauges, because a status transition moves them down', async () => {
    const parsed = parseExposition(await renderMetrics())

    expect(parsed.types.get('gateway_messages_total')).toBe('gauge')
    expect(parsed.types.get('gateway_instances_total')).toBe('gauge')
    expect(parsed.types.get('gateway_webhooks_total')).toBe('gauge')
  })

  it('carries build identity in labels with a constant value of 1', async () => {
    const parsed = parseExposition(await renderMetrics())
    const [buildInfo] = samplesFor(parsed, 'gateway_build_info')

    expect(buildInfo?.value).toBe('1')
    expect(buildInfo?.labels.baileys_package).toBe('v7rc')
    expect(buildInfo?.labels.node_id).toBe('test-node')
  })

  it('takes the live socket count from the socket manager', async () => {
    const parsed = parseExposition(await renderMetrics())

    expect(samplesFor(parsed, 'gateway_sockets_live')[0]?.value).toBe('2')
  })

  it('omits database families rather than publishing a false zero when the query fails', async () => {
    prismaMock.instance.groupBy.mockRejectedValue(new Error('connection terminated'))

    const parsed = parseExposition(await renderMetrics())

    // A zero would look identical to an empty fleet and would silently resolve
    // every alert at the moment the database died.
    expect(parsed.types.has('gateway_instances_total')).toBe(false)
    expect(parsed.types.has('gateway_messages_total')).toBe(false)
    // Process-level families still scrape, so the node is not invisible.
    expect(parsed.types.has('gateway_uptime_seconds')).toBe(true)
    expect(parsed.types.has('gateway_build_info')).toBe(true)
  })
})

describe('escaping', () => {
  it('escapes backslashes, quotes and newlines in label values', () => {
    expect(escapeLabelValue('say "hi"')).toBe('say \\"hi\\"')
    expect(escapeLabelValue('C:\\path')).toBe('C:\\\\path')
    expect(escapeLabelValue('one\ntwo')).toBe('one\\ntwo')
  })

  it('escapes the backslash before the quote, so an escaped quote is not double-escaped', () => {
    // Naive ordering turns `"` into `\"` and then mangles that backslash,
    // producing `\\"` — which closes the label value early and corrupts the line.
    expect(escapeLabelValue('a\\"b')).toBe('a\\\\\\"b')
  })

  it('round-trips a hostile label value through render and parse', () => {
    const nasty = 'weird "value" with \\ backslash\nand a newline'

    const rendered = renderMetricFamily({
      name: 'gateway_build_info',
      help: 'Build identity.',
      type: 'gauge',
      samples: [{ labels: { baileys_package: nasty }, value: 1 }],
    })

    const parsed = parseExposition(`${rendered}\n`)
    const [sample] = samplesFor(parsed, 'gateway_build_info')

    expect(sample?.labels.baileys_package).toBe(nasty)
    expect(sample?.value).toBe('1')
  })

  it('keeps HELP text on a single line', () => {
    const rendered = renderMetricFamily({
      name: 'gateway_uptime_seconds',
      help: 'Line one.\nLine two.',
      type: 'gauge',
      samples: [{ value: 5 }],
    })

    expect(rendered.split('\n')).toHaveLength(3)
    expect(rendered).toContain('# HELP gateway_uptime_seconds Line one.\\nLine two.')
  })

  it('spells non-finite values the way Prometheus requires', () => {
    expect(formatMetricValue(Number.NaN)).toBe('NaN')
    expect(formatMetricValue(Number.POSITIVE_INFINITY)).toBe('+Inf')
    expect(formatMetricValue(Number.NEGATIVE_INFINITY)).toBe('-Inf')
    expect(formatMetricValue(7)).toBe('7')
    expect(formatMetricValue(1.23456789)).toBe('1.234568')
  })
})
