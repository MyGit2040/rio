import { describe, expect, it } from 'vitest'

import { ReconnectPacer } from '../../src/instances/reconnect-pacer.js'

const WINDOW = 60_000
const MAX_EXTRA = 4000

describe('ReconnectPacer', () => {
  it('adds no delay when no ramp is active', () => {
    const pacer = new ReconnectPacer()
    expect(pacer.extraDelayMs('a', 1000)).toBe(0)
    expect(pacer.isRamping('a', 1000)).toBe(false)
  })

  it('delays most right after recovery and decays to zero across the window', () => {
    const pacer = new ReconnectPacer()
    const start = 1_000_000
    pacer.begin('a', WINDOW, MAX_EXTRA, start)

    // At the very start the extra pause is ~the maximum.
    expect(pacer.extraDelayMs('a', start)).toBe(MAX_EXTRA)

    // Halfway through, ~half.
    expect(pacer.extraDelayMs('a', start + WINDOW / 2)).toBe(MAX_EXTRA / 2)

    // Just before the end, a small pause.
    expect(pacer.extraDelayMs('a', start + WINDOW - 6_000)).toBe(Math.round(MAX_EXTRA * (6_000 / WINDOW)))

    // At/after the end, nothing.
    expect(pacer.extraDelayMs('a', start + WINDOW)).toBe(0)
    expect(pacer.extraDelayMs('a', start + WINDOW + 1)).toBe(0)
  })

  it('decreases monotonically through the window', () => {
    const pacer = new ReconnectPacer()
    const start = 0
    pacer.begin('a', WINDOW, MAX_EXTRA, start)

    let previous = Infinity
    for (let t = 0; t <= WINDOW; t += 5_000) {
      const delay = pacer.extraDelayMs('a', start + t)
      expect(delay).toBeLessThanOrEqual(previous)
      previous = delay
    }
  })

  it('clears the ramp once the window has elapsed (no unbounded growth)', () => {
    const pacer = new ReconnectPacer()
    pacer.begin('a', WINDOW, MAX_EXTRA, 0)
    // Reading past the end must retire the ramp.
    pacer.extraDelayMs('a', WINDOW + 1)
    expect(pacer.isRamping('a', WINDOW + 1)).toBe(false)
  })

  it('treats a zero window or zero max-extra as "no ramp"', () => {
    const pacer = new ReconnectPacer()
    pacer.begin('a', 0, MAX_EXTRA, 0)
    expect(pacer.isRamping('a', 0)).toBe(false)

    pacer.begin('b', WINDOW, 0, 0)
    expect(pacer.extraDelayMs('b', 0)).toBe(0)
  })

  it('keeps ramps independent per instance and honours clear()', () => {
    const pacer = new ReconnectPacer()
    pacer.begin('a', WINDOW, MAX_EXTRA, 0)
    pacer.begin('b', WINDOW, MAX_EXTRA, 0)

    pacer.clear('a')
    expect(pacer.extraDelayMs('a', 0)).toBe(0)
    expect(pacer.extraDelayMs('b', 0)).toBe(MAX_EXTRA)
  })
})
