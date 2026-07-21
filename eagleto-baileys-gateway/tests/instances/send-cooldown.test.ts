import { describe, expect, it } from 'vitest'

import { SendCooldownRegistry } from '../../src/instances/send-cooldown.js'

describe('SendCooldownRegistry', () => {
  it('reports no cooldown by default', () => {
    const registry = new SendCooldownRegistry()
    expect(registry.active('a', 1000)).toBeNull()
  })

  it('holds a cooldown until it expires, then clears itself', () => {
    const registry = new SendCooldownRegistry()
    const t0 = 1_000_000
    registry.set('a', 60_000, 'session health critical', t0)

    const active = registry.active('a', t0 + 30_000)
    expect(active).not.toBeNull()
    expect(active?.reason).toBe('session health critical')
    expect(active?.until).toBe(t0 + 60_000)

    // At/after expiry it is gone.
    expect(registry.active('a', t0 + 60_000)).toBeNull()
    expect(registry.active('a', t0 + 60_001)).toBeNull()
  })

  it('lets a new cooldown replace an existing one', () => {
    const registry = new SendCooldownRegistry()
    registry.set('a', 60_000, 'first', 0)
    registry.set('a', 3600_000, 'delivery ratio', 0)
    expect(registry.active('a', 0)?.reason).toBe('delivery ratio')
    expect(registry.active('a', 0)?.until).toBe(3600_000)
  })

  it('clears on demand (e.g. on re-link)', () => {
    const registry = new SendCooldownRegistry()
    registry.set('a', 60_000, 'x', 0)
    registry.clear('a')
    expect(registry.active('a', 0)).toBeNull()
  })
})
