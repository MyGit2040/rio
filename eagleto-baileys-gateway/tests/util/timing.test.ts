import { describe, expect, it } from 'vitest'

import { gaussianBetween, randomBetween } from '../../src/util/timing.js'

describe('randomBetween', () => {
  it('stays within the inclusive band', () => {
    for (let i = 0; i < 2000; i++) {
      const v = randomBetween(2000, 7000)
      expect(v).toBeGreaterThanOrEqual(2000)
      expect(v).toBeLessThanOrEqual(7000)
    }
  })

  it('collapses an empty or inverted range to the floor', () => {
    expect(randomBetween(5, 5)).toBe(5)
    expect(randomBetween(9, 3)).toBe(9)
  })
})

describe('gaussianBetween', () => {
  it('never escapes the clamped band', () => {
    for (let i = 0; i < 5000; i++) {
      const v = gaussianBetween(2000, 7000)
      expect(v).toBeGreaterThanOrEqual(2000)
      expect(v).toBeLessThanOrEqual(7000)
    }
  })

  it('clusters around the midpoint (unlike a uniform draw)', () => {
    const min = 0
    const max = 9000
    const lower = 3000
    const upper = 6000
    const sample = 5000
    let inMiddle = 0
    let sum = 0

    for (let i = 0; i < sample; i++) {
      const v = gaussianBetween(min, max)
      sum += v
      if (v >= lower && v <= upper) {
        inMiddle++
      }
    }

    // A normal draw piles up in the middle third; a uniform one would put ~1/3.
    expect(inMiddle).toBeGreaterThan(sample * 0.5)
    // Mean near the centre (4500), give or take sampling noise.
    expect(sum / sample).toBeGreaterThan(4000)
    expect(sum / sample).toBeLessThan(5000)
  })

  it('collapses an empty range to the floor', () => {
    expect(gaussianBetween(3000, 3000)).toBe(3000)
  })
})
