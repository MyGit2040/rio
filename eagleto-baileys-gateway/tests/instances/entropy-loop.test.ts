import { describe, expect, it } from 'vitest'

import { chooseAction, nextIntervalMs } from '../../src/instances/entropy-loop.js'

describe('nextIntervalMs', () => {
  it('stays within the requested hour band (in ms)', () => {
    for (let i = 0; i < 2000; i++) {
      const ms = nextIntervalMs(2, 6)
      expect(ms).toBeGreaterThanOrEqual(2 * 3_600_000)
      expect(ms).toBeLessThanOrEqual(6 * 3_600_000)
    }
  })

  it('handles a collapsed band', () => {
    expect(nextIntervalMs(3, 3)).toBe(3 * 3_600_000)
  })

  it('tolerates an inverted band without escaping the floor', () => {
    for (let i = 0; i < 200; i++) {
      expect(nextIntervalMs(6, 2)).toBe(6 * 3_600_000)
    }
  })
})

describe('chooseAction', () => {
  it('is deterministic given the random input', () => {
    expect(chooseAction(0.1)).toBe('presence')
    expect(chooseAction(0.9)).toBe('profile')
  })

  it('covers both actions across the range', () => {
    const seen = new Set<string>()
    for (let r = 0; r < 1; r += 0.05) {
      seen.add(chooseAction(r))
    }
    expect(seen).toContain('presence')
    expect(seen).toContain('profile')
  })
})
