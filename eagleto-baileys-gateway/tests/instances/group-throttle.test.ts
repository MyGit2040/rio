import { describe, expect, it } from 'vitest'

import { GroupActionThrottle } from '../../src/instances/group-throttle.js'

const COOLDOWN = 15_000

describe('GroupActionThrottle', () => {
  it('lets the first group action go immediately', () => {
    const throttle = new GroupActionThrottle()
    expect(throttle.waitMs('a', COOLDOWN, 1000)).toBe(0)
  })

  it('forces a full cooldown right after an action, decaying to zero', () => {
    const throttle = new GroupActionThrottle()
    const t0 = 1_000_000
    throttle.mark('a', t0)

    expect(throttle.waitMs('a', COOLDOWN, t0)).toBe(COOLDOWN)
    expect(throttle.waitMs('a', COOLDOWN, t0 + 5_000)).toBe(10_000)
    expect(throttle.waitMs('a', COOLDOWN, t0 + COOLDOWN)).toBe(0)
    expect(throttle.waitMs('a', COOLDOWN, t0 + COOLDOWN + 1)).toBe(0)
  })

  it('keeps instances independent', () => {
    const throttle = new GroupActionThrottle()
    const t0 = 0
    throttle.mark('a', t0)

    expect(throttle.waitMs('a', COOLDOWN, t0)).toBe(COOLDOWN)
    expect(throttle.waitMs('b', COOLDOWN, t0)).toBe(0)
  })

  it('resets after clear()', () => {
    const throttle = new GroupActionThrottle()
    throttle.mark('a', 0)
    throttle.clear('a')
    expect(throttle.waitMs('a', COOLDOWN, 0)).toBe(0)
  })
})
