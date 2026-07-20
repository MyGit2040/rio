import { afterEach, describe, expect, it, vi } from 'vitest'

// The dispatcher module imports the Prisma client at load time; the backoff
// curve itself is pure, so the database is stubbed out entirely here.
vi.mock('../../src/database/client.js', () => ({ prisma: {} }))

const {
  BACKOFF_SCHEDULE_SECONDS,
  MAX_BACKOFF_SECONDS,
  backoffDelaySeconds,
} = await import('../../src/webhooks/webhook-dispatcher.js')

const JITTER_RATIO = 0.2

/** Math.random() === 0 gives the maximum downward jitter, 1 the maximum upward. */
function withRandom<T>(value: number, run: () => T): T {
  const spy = vi.spyOn(Math, 'random').mockReturnValue(value)

  try {
    return run()
  } finally {
    spy.mockRestore()
  }
}

function lowest(attempt: number): number {
  return withRandom(0, () => backoffDelaySeconds(attempt))
}

function highest(attempt: number): number {
  return withRandom(1, () => backoffDelaySeconds(attempt))
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('backoffDelaySeconds', () => {
  it('follows the published schedule, within jitter', () => {
    BACKOFF_SCHEDULE_SECONDS.forEach((base, index) => {
      const attempt = index + 1
      const capped = Math.min(MAX_BACKOFF_SECONDS, base)

      expect(lowest(attempt)).toBe(Math.round(capped - base * JITTER_RATIO))
      expect(highest(attempt)).toBe(Math.min(MAX_BACKOFF_SECONDS, Math.round(base + base * JITTER_RATIO)))
    })
  })

  it('grows monotonically — even the longest delay for one attempt is shorter than the shortest for the next', () => {
    // Stated as non-overlapping ranges rather than "on average larger", because
    // jitter must never be wide enough to reorder two steps of the schedule.
    for (let attempt = 1; attempt < BACKOFF_SCHEDULE_SECONDS.length; attempt += 1) {
      expect(highest(attempt)).toBeLessThan(lowest(attempt + 1))
    }
  })

  it('never exceeds the cap, however many attempts have been made', () => {
    for (const attempt of [6, 7, 10, 50, 1_000, Number.MAX_SAFE_INTEGER]) {
      expect(highest(attempt)).toBeLessThanOrEqual(MAX_BACKOFF_SECONDS)
      expect(backoffDelaySeconds(attempt)).toBeLessThanOrEqual(MAX_BACKOFF_SECONDS)
    }
  })

  it('holds at the cap once the schedule is exhausted', () => {
    const beyond = BACKOFF_SCHEDULE_SECONDS.length + 1

    expect(lowest(beyond)).toBe(Math.round(MAX_BACKOFF_SECONDS * (1 - JITTER_RATIO)))
    expect(highest(beyond)).toBe(MAX_BACKOFF_SECONDS)
  })

  it('keeps jitter inside ±20% of the scheduled base', () => {
    for (let attempt = 1; attempt <= BACKOFF_SCHEDULE_SECONDS.length; attempt += 1) {
      const base = BACKOFF_SCHEDULE_SECONDS[attempt - 1] ?? MAX_BACKOFF_SECONDS

      for (let sample = 0; sample < 500; sample += 1) {
        const delay = backoffDelaySeconds(attempt)

        expect(delay).toBeGreaterThanOrEqual(Math.floor(base * (1 - JITTER_RATIO)))
        expect(delay).toBeLessThanOrEqual(Math.min(MAX_BACKOFF_SECONDS, Math.ceil(base * (1 + JITTER_RATIO))))
      }
    }
  })

  it('actually jitters — a backlog must not become due all at once', () => {
    const observed = new Set(Array.from({ length: 200 }, () => backoffDelaySeconds(4)))

    expect(observed.size).toBeGreaterThan(1)
  })

  it('always returns a whole number of at least one second', () => {
    for (const attempt of [-5, 0, 1, 2, 3, 4, 5, 6, 99]) {
      for (let sample = 0; sample < 50; sample += 1) {
        const delay = backoffDelaySeconds(attempt)

        expect(Number.isInteger(delay)).toBe(true)
        expect(delay).toBeGreaterThanOrEqual(1)
      }
    }
  })

  it('treats a zero or negative attempt as the first step rather than an immediate retry', () => {
    // Defensive: a caller that passes a raw counter starting at 0 must not get
    // a zero-second delay and spin.
    expect(lowest(0)).toBe(lowest(1))
    expect(lowest(-3)).toBe(lowest(1))
  })

  it('ignores a fractional attempt by flooring it', () => {
    expect(lowest(2.9)).toBe(lowest(2))
  })
})
