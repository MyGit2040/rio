import { describe, expect, it } from 'vitest'

import { planTyping, type TypingModel } from '../../src/baileys/presence-choreographer.js'

const MODEL: TypingModel = {
  msPerChar: 30,
  minTypeMs: 900,
  maxTypeMs: 6000,
  thinkMinMs: 500,
  thinkMaxMs: 1500,
}

describe('planTyping', () => {
  it('keeps the compose window within [minTypeMs, maxTypeMs] across many draws', () => {
    for (let length = 0; length <= 4000; length += 37) {
      const plan = planTyping(length, MODEL)
      expect(plan.typeMs).toBeGreaterThanOrEqual(MODEL.minTypeMs)
      expect(plan.typeMs).toBeLessThanOrEqual(MODEL.maxTypeMs)
    }
  })

  it('keeps the think pause within its configured 500–1500 ms range', () => {
    for (let i = 0; i < 500; i++) {
      const plan = planTyping(50, MODEL)
      expect(plan.thinkMs).toBeGreaterThanOrEqual(MODEL.thinkMinMs)
      expect(plan.thinkMs).toBeLessThanOrEqual(MODEL.thinkMaxMs)
    }
  })

  it('sizes the compose window around ~msPerChar per character before the clamp', () => {
    // A 100-char message at 30 ms/char ≈ 3000 ms; with ±20% jitter it must land
    // in [2400, 3600], comfortably inside the [900, 6000] clamp.
    const avg = (length: number): number => {
      let sum = 0
      for (let i = 0; i < 500; i++) {
        sum += planTyping(length, MODEL).typeMs
      }
      return sum / 500
    }

    const mean = avg(100)
    expect(mean).toBeGreaterThan(2400)
    expect(mean).toBeLessThan(3600)
  })

  it('clamps a short message up to the floor when its natural time is below it', () => {
    // 10 chars * 30 ms = 300 ms, below the 900 ms floor → must clamp up.
    for (let i = 0; i < 200; i++) {
      expect(planTyping(10, MODEL).typeMs).toBe(MODEL.minTypeMs)
    }
  })

  it('caps a very long message so it cannot stall the serial queue (ceiling)', () => {
    for (let i = 0; i < 200; i++) {
      expect(planTyping(100_000, MODEL).typeMs).toBe(MODEL.maxTypeMs)
    }
  })

  it('longer text tends to take longer to type', () => {
    const avg = (length: number): number => {
      let sum = 0
      for (let i = 0; i < 400; i++) {
        sum += planTyping(length, MODEL).typeMs
      }
      return sum / 400
    }

    expect(avg(150)).toBeGreaterThan(avg(40))
  })
})
