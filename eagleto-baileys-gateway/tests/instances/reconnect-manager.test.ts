import pino, { type Logger } from 'pino'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ReconnectManager, reconnectDelaySeconds } from '../../src/baileys/reconnect-manager.js'

const MAX_DELAY = 300
const MAX_ATTEMPTS = 8
const WINDOW_MINUTES = 30

let silent: Logger

function manager(overrides: Partial<ConstructorParameters<typeof ReconnectManager>[0]> = {}): ReconnectManager {
  return new ReconnectManager({
    maxAttempts: MAX_ATTEMPTS,
    windowMinutes: WINDOW_MINUTES,
    maxDelaySeconds: MAX_DELAY,
    logger: silent,
    ...overrides,
  })
}

beforeEach(() => {
  silent = pino({ level: 'silent' })
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('reconnectDelaySeconds', () => {
  /** ±20% jitter around each rung. */
  const rungs: ReadonlyArray<readonly [number, number, number]> = [
    [1, 4, 6],
    [2, 12, 18],
    [3, 24, 36],
    [4, 48, 72],
    [5, 96, 144],
  ]

  it.each(rungs)('attempt %i lands within jitter of its rung', (attempt, low, high) => {
    for (let run = 0; run < 200; run += 1) {
      const delay = reconnectDelaySeconds(attempt, MAX_DELAY)

      expect(delay).toBeGreaterThanOrEqual(low)
      expect(delay).toBeLessThanOrEqual(high)
    }
  })

  it('grows across the ladder even at the extremes of the jitter', () => {
    // The highest possible value of one rung stays below the lowest possible
    // value of the next, so backoff is monotonic despite the randomness.
    for (let i = 0; i < rungs.length - 1; i += 1) {
      expect(rungs[i]?.[2]).toBeLessThan(rungs[i + 1]?.[1] ?? Infinity)
    }
  })

  it('never exceeds the cap, however many attempts have failed', () => {
    for (const attempt of [6, 7, 10, 25, 100, 1_000]) {
      for (let run = 0; run < 50; run += 1) {
        expect(reconnectDelaySeconds(attempt, MAX_DELAY)).toBeLessThanOrEqual(MAX_DELAY)
      }
    }
  })

  it('respects a low cap from the very first attempt', () => {
    for (let run = 0; run < 100; run += 1) {
      const delay = reconnectDelaySeconds(1, 3)

      expect(delay).toBeGreaterThanOrEqual(1)
      expect(delay).toBeLessThanOrEqual(3)
    }
  })

  it('reaches the cap once the ladder is exhausted', () => {
    const delays = Array.from({ length: 100 }, () => reconnectDelaySeconds(12, MAX_DELAY))

    // At the cap the only variation left is downward jitter.
    expect(Math.max(...delays)).toBeGreaterThan(MAX_DELAY * 0.9)
    expect(Math.max(...delays)).toBeLessThanOrEqual(MAX_DELAY)
  })

  it('never returns a zero or negative delay', () => {
    for (const attempt of [-5, 0, 1, 2, Number.NaN]) {
      expect(reconnectDelaySeconds(attempt, MAX_DELAY)).toBeGreaterThanOrEqual(1)
    }
  })

  it('applies jitter rather than a fixed value', () => {
    const seen = new Set(Array.from({ length: 200 }, () => reconnectDelaySeconds(4, MAX_DELAY)))

    expect(seen.size).toBeGreaterThan(1)
  })
})

describe('shouldGiveUp', () => {
  const now = new Date('2026-01-01T12:00:00.000Z')
  const minutesAgo = (minutes: number) => new Date(now.getTime() - minutes * 60_000)

  it('keeps trying below the attempt limit', () => {
    expect(manager().shouldGiveUp(MAX_ATTEMPTS - 1, minutesAgo(1), now)).toBe(false)
  })

  it('trips exactly at the limit inside the window', () => {
    expect(manager().shouldGiveUp(MAX_ATTEMPTS, minutesAgo(1), now)).toBe(true)
  })

  it('stays tripped beyond the limit inside the window', () => {
    expect(manager().shouldGiveUp(MAX_ATTEMPTS + 20, minutesAgo(WINDOW_MINUTES - 1), now)).toBe(true)
  })

  it('resets once the window has elapsed', () => {
    expect(manager().shouldGiveUp(MAX_ATTEMPTS + 20, minutesAgo(WINDOW_MINUTES + 1), now)).toBe(false)
  })

  it('resets exactly on the window boundary', () => {
    expect(manager().shouldGiveUp(MAX_ATTEMPTS, minutesAgo(WINDOW_MINUTES), now)).toBe(false)
  })

  it('does not retire an instance that failed slowly over a long life', () => {
    // Eight drops spread over a month is a healthy long-lived number, not a
    // failing one: the window makes the counter stale.
    expect(manager().shouldGiveUp(MAX_ATTEMPTS, minutesAgo(60 * 24 * 30), now)).toBe(false)
  })

  it('gives up at the limit when no window was recorded', () => {
    expect(manager().shouldGiveUp(MAX_ATTEMPTS, null, now)).toBe(true)
  })

  it('keeps trying with no window recorded below the limit', () => {
    expect(manager().shouldGiveUp(0, null, now)).toBe(false)
  })
})

describe('schedule', () => {
  it('runs the attempt after the backoff delay, not before', async () => {
    vi.useFakeTimers()

    const run = vi.fn().mockResolvedValue(undefined)
    const m = manager()

    m.schedule('inst_1', 1, run)
    expect(m.isScheduled('inst_1')).toBe(true)

    // Attempt 1 is 5s ±20%, so nothing may fire in the first 3s.
    await vi.advanceTimersByTimeAsync(3_000)
    expect(run).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(4_000)
    expect(run).toHaveBeenCalledTimes(1)
    expect(m.isScheduled('inst_1')).toBe(false)
  })

  it('keeps one timer per instance when a close is reported repeatedly', async () => {
    vi.useFakeTimers()

    const run = vi.fn().mockResolvedValue(undefined)
    const m = manager()

    m.schedule('inst_1', 1, run)
    m.schedule('inst_1', 1, run)
    m.schedule('inst_1', 1, run)

    expect(m.pendingCount()).toBe(1)

    await vi.advanceTimersByTimeAsync(10_000)

    expect(run).toHaveBeenCalledTimes(1)
  })

  it('schedules instances independently', async () => {
    vi.useFakeTimers()

    const runA = vi.fn().mockResolvedValue(undefined)
    const runB = vi.fn().mockResolvedValue(undefined)
    const m = manager()

    m.schedule('inst_a', 1, runA)
    m.schedule('inst_b', 1, runB)

    expect(m.pendingCount()).toBe(2)

    await vi.advanceTimersByTimeAsync(10_000)

    expect(runA).toHaveBeenCalledTimes(1)
    expect(runB).toHaveBeenCalledTimes(1)
  })

  it('survives an attempt that rejects', async () => {
    vi.useFakeTimers()

    const run = vi.fn().mockRejectedValue(new Error('socket refused'))
    const m = manager()

    m.schedule('inst_1', 1, run)

    await expect(vi.advanceTimersByTimeAsync(10_000)).resolves.not.toThrow()
    expect(run).toHaveBeenCalledTimes(1)
    expect(m.pendingCount()).toBe(0)
  })

  it('lets the attempt schedule the next one', async () => {
    vi.useFakeTimers()

    const m = manager()
    let attempts = 0

    const run = async (): Promise<void> => {
      attempts += 1

      if (attempts < 3) {
        m.schedule('inst_1', attempts + 1, run)
      }
    }

    m.schedule('inst_1', 1, run)
    await vi.advanceTimersByTimeAsync(200_000)

    expect(attempts).toBe(3)
    expect(m.pendingCount()).toBe(0)
  })
})

describe('cancel', () => {
  it('stops a pending attempt', async () => {
    vi.useFakeTimers()

    const run = vi.fn().mockResolvedValue(undefined)
    const m = manager()

    m.schedule('inst_1', 1, run)
    m.cancel('inst_1')

    await vi.advanceTimersByTimeAsync(60_000)

    expect(run).not.toHaveBeenCalled()
    expect(m.isScheduled('inst_1')).toBe(false)
  })

  it('is safe for an instance with nothing scheduled', () => {
    expect(() => manager().cancel('unknown')).not.toThrow()
  })

  it('cancelAll stops every attempt, as graceful shutdown requires', async () => {
    vi.useFakeTimers()

    const run = vi.fn().mockResolvedValue(undefined)
    const m = manager()

    m.schedule('inst_a', 1, run)
    m.schedule('inst_b', 2, run)
    m.schedule('inst_c', 3, run)
    expect(m.pendingCount()).toBe(3)

    m.cancelAll()

    await vi.advanceTimersByTimeAsync(600_000)

    expect(run).not.toHaveBeenCalled()
    expect(m.pendingCount()).toBe(0)
  })
})

describe('delayFor', () => {
  it('uses the manager cap', () => {
    const m = manager({ maxDelaySeconds: 10 })

    for (let run = 0; run < 50; run += 1) {
      expect(m.delayFor(9)).toBeLessThanOrEqual(10)
    }
  })
})
