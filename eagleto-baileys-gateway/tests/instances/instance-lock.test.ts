import pino, { type Logger } from 'pino'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  InstanceLock,
  RELEASE_SCRIPT,
  RENEW_SCRIPT,
  type RedisLike,
  lockKey,
} from '../../src/instances/instance-lock.js'

/**
 * In-memory Redis double.
 *
 * The lease is the one mechanism that must never be skipped for want of a
 * running service, so the tests carry their own Redis. `eval` recognises the
 * two scripts by identity and reproduces their semantics in JS — which means a
 * change to the Lua that alters its contract will not be caught here, but every
 * caller-side guarantee (compare before extend, compare before delete, the
 * arguments actually sent) is.
 */
class FakeRedis implements RedisLike {
  readonly #store = new Map<string, { value: string; expiresAtMs: number }>()
  #nowMs = Date.UTC(2026, 0, 1)

  /** Calls recorded so tests can assert the exact command shape. */
  readonly evalCalls: Array<{ script: string; args: (string | number)[] }> = []

  advance(ms: number): void {
    this.#nowMs += ms
  }

  #live(key: string): { value: string; expiresAtMs: number } | undefined {
    const entry = this.#store.get(key)

    if (!entry) {
      return undefined
    }

    if (entry.expiresAtMs <= this.#nowMs) {
      this.#store.delete(key)

      return undefined
    }

    return entry
  }

  async set(key: string, value: string, _mode: 'EX', seconds: number, _condition: 'NX'): Promise<'OK' | null> {
    if (this.#live(key)) {
      return null
    }

    this.#store.set(key, { value, expiresAtMs: this.#nowMs + seconds * 1000 })

    return 'OK'
  }

  async get(key: string): Promise<string | null> {
    return this.#live(key)?.value ?? null
  }

  async eval(script: string, _numKeys: number, ...args: (string | number)[]): Promise<unknown> {
    this.evalCalls.push({ script, args })

    const key = String(args[0])
    const expected = String(args[1])
    const entry = this.#live(key)

    if (!entry || entry.value !== expected) {
      return 0
    }

    if (script === RENEW_SCRIPT) {
      entry.expiresAtMs = this.#nowMs + Number(args[2]) * 1000

      return 1
    }

    if (script === RELEASE_SCRIPT) {
      this.#store.delete(key)

      return 1
    }

    throw new Error('unexpected script')
  }

  /** Test helper: what a third party sees in Redis. */
  peek(key: string): string | null {
    return this.#live(key)?.value ?? null
  }
}

const INSTANCE_ID = 'inst_1'
const TTL = 60
const RENEW = 20

let redis: FakeRedis
let silent: Logger

function lockFor(nodeId: string): InstanceLock {
  return new InstanceLock(redis, { nodeId, ttlSeconds: TTL, renewSeconds: RENEW, logger: silent })
}

beforeEach(() => {
  redis = new FakeRedis()
  silent = pino({ level: 'silent' })
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('acquire', () => {
  it('grants the lease to the first node', async () => {
    const nodeA = lockFor('node-a')

    await expect(nodeA.acquire(INSTANCE_ID)).resolves.toBe(true)
    expect(redis.peek(lockKey(INSTANCE_ID))).toBe('node-a')
  })

  it('refuses a second node while the lease is held', async () => {
    const nodeA = lockFor('node-a')
    const nodeB = lockFor('node-b')

    await expect(nodeA.acquire(INSTANCE_ID)).resolves.toBe(true)
    await expect(nodeB.acquire(INSTANCE_ID)).resolves.toBe(false)

    // The loser must not have overwritten the holder.
    expect(redis.peek(lockKey(INSTANCE_ID))).toBe('node-a')
  })

  it('lets the same node reclaim its own lease after a restart', async () => {
    const first = lockFor('node-a')
    await first.acquire(INSTANCE_ID)

    // A fresh process object with the same node id, as after a crash.
    const restarted = lockFor('node-a')

    await expect(restarted.acquire(INSTANCE_ID)).resolves.toBe(true)
  })

  it('lets another node take over once the lease expires', async () => {
    const nodeA = lockFor('node-a')
    const nodeB = lockFor('node-b')

    await nodeA.acquire(INSTANCE_ID)
    redis.advance((TTL + 1) * 1000)

    await expect(nodeB.acquire(INSTANCE_ID)).resolves.toBe(true)
    expect(redis.peek(lockKey(INSTANCE_ID))).toBe('node-b')
  })
})

describe('renew', () => {
  it('extends the lease for the owner', async () => {
    const nodeA = lockFor('node-a')
    await nodeA.acquire(INSTANCE_ID)

    redis.advance((TTL - 5) * 1000)

    await expect(nodeA.renew(INSTANCE_ID)).resolves.toBe(true)

    // Past the ORIGINAL expiry, the lease survives because it was extended.
    redis.advance(10 * 1000)
    expect(redis.peek(lockKey(INSTANCE_ID))).toBe('node-a')
  })

  it('fails for a node that does not own the lease', async () => {
    const nodeA = lockFor('node-a')
    const nodeB = lockFor('node-b')

    await nodeA.acquire(INSTANCE_ID)

    await expect(nodeB.renew(INSTANCE_ID)).resolves.toBe(false)
    expect(redis.peek(lockKey(INSTANCE_ID))).toBe('node-a')
  })

  it('fails when there is no lease at all', async () => {
    await expect(lockFor('node-a').renew(INSTANCE_ID)).resolves.toBe(false)
  })

  it('sends a compare-and-extend, not a bare expire', async () => {
    const nodeA = lockFor('node-a')
    await nodeA.acquire(INSTANCE_ID)
    redis.evalCalls.length = 0

    await nodeA.renew(INSTANCE_ID)

    expect(redis.evalCalls).toHaveLength(1)
    const call = redis.evalCalls[0]
    expect(call?.script).toBe(RENEW_SCRIPT)
    // key, the node id it must still equal, then the new ttl
    expect(call?.args).toEqual([lockKey(INSTANCE_ID), 'node-a', TTL])
  })
})

describe('release', () => {
  it('drops the lease for the owner', async () => {
    const nodeA = lockFor('node-a')
    await nodeA.acquire(INSTANCE_ID)

    await nodeA.release(INSTANCE_ID)

    expect(redis.peek(lockKey(INSTANCE_ID))).toBeNull()
  })

  it('does not delete a lease owned by another node', async () => {
    const nodeA = lockFor('node-a')
    const nodeB = lockFor('node-b')

    await nodeA.acquire(INSTANCE_ID)
    await nodeB.release(INSTANCE_ID)

    expect(redis.peek(lockKey(INSTANCE_ID))).toBe('node-a')
    await expect(nodeA.isOwned(INSTANCE_ID)).resolves.toBe(true)
  })

  it('sends a compare-and-delete', async () => {
    const nodeA = lockFor('node-a')
    await nodeA.acquire(INSTANCE_ID)
    redis.evalCalls.length = 0

    await nodeA.release(INSTANCE_ID)

    expect(redis.evalCalls[0]?.script).toBe(RELEASE_SCRIPT)
    expect(redis.evalCalls[0]?.args).toEqual([lockKey(INSTANCE_ID), 'node-a'])
  })
})

describe('isOwned', () => {
  it('is true only for the holder', async () => {
    const nodeA = lockFor('node-a')
    const nodeB = lockFor('node-b')

    await nodeA.acquire(INSTANCE_ID)

    await expect(nodeA.isOwned(INSTANCE_ID)).resolves.toBe(true)
    await expect(nodeB.isOwned(INSTANCE_ID)).resolves.toBe(false)
  })
})

describe('startRenewal', () => {
  it('keeps renewing while the lease is held', async () => {
    vi.useFakeTimers()

    const nodeA = lockFor('node-a')
    await nodeA.acquire(INSTANCE_ID)

    const onLost = vi.fn()
    nodeA.startRenewal(INSTANCE_ID, onLost)

    for (let tick = 0; tick < 3; tick += 1) {
      await vi.advanceTimersByTimeAsync(RENEW * 1000)
    }

    expect(onLost).not.toHaveBeenCalled()
    expect(nodeA.renewingInstanceIds()).toEqual([INSTANCE_ID])
  })

  it('fires onLost as soon as a renewal fails', async () => {
    vi.useFakeTimers()

    const nodeA = lockFor('node-a')
    await nodeA.acquire(INSTANCE_ID)

    const onLost = vi.fn()
    nodeA.startRenewal(INSTANCE_ID, onLost)

    // The lease lapses (a stalled process, a Redis failover) and another node
    // claims it. node-a's compare-and-extend can no longer match.
    redis.advance((TTL + 1) * 1000)
    await lockFor('node-b').acquire(INSTANCE_ID)

    await vi.advanceTimersByTimeAsync(RENEW * 1000)

    expect(onLost).toHaveBeenCalledTimes(1)
    // node-a must not have stolen it back on the way out.
    expect(redis.peek(lockKey(INSTANCE_ID))).toBe('node-b')
  })

  it('stops renewing once the lease is lost, so onLost fires once', async () => {
    vi.useFakeTimers()

    const nodeA = lockFor('node-a')
    const onLost = vi.fn()

    nodeA.startRenewal(INSTANCE_ID, onLost)

    await vi.advanceTimersByTimeAsync(RENEW * 1000 * 5)

    expect(onLost).toHaveBeenCalledTimes(1)
    expect(nodeA.renewingInstanceIds()).toEqual([])
  })

  it('treats an unreachable Redis as a lost lease', async () => {
    vi.useFakeTimers()

    const nodeA = lockFor('node-a')
    await nodeA.acquire(INSTANCE_ID)

    vi.spyOn(redis, 'eval').mockRejectedValue(new Error('connection refused'))

    const onLost = vi.fn()
    nodeA.startRenewal(INSTANCE_ID, onLost)

    await vi.advanceTimersByTimeAsync(RENEW * 1000)

    expect(onLost).toHaveBeenCalledTimes(1)
  })

  it('does not fire onLost for a deliberate release', async () => {
    vi.useFakeTimers()

    const nodeA = lockFor('node-a')
    await nodeA.acquire(INSTANCE_ID)

    const onLost = vi.fn()
    nodeA.startRenewal(INSTANCE_ID, onLost)

    await nodeA.release(INSTANCE_ID)
    await vi.advanceTimersByTimeAsync(RENEW * 1000 * 3)

    expect(onLost).not.toHaveBeenCalled()
  })

  it('replaces an existing renewal rather than stacking timers', async () => {
    vi.useFakeTimers()

    const nodeA = lockFor('node-a')
    await nodeA.acquire(INSTANCE_ID)

    nodeA.startRenewal(INSTANCE_ID, vi.fn())
    nodeA.startRenewal(INSTANCE_ID, vi.fn())

    expect(nodeA.renewingInstanceIds()).toEqual([INSTANCE_ID])

    redis.evalCalls.length = 0
    await vi.advanceTimersByTimeAsync(RENEW * 1000)

    expect(redis.evalCalls).toHaveLength(1)
  })

  it('stopAllRenewals clears every timer', async () => {
    vi.useFakeTimers()

    const nodeA = lockFor('node-a')
    await nodeA.acquire('a')
    await nodeA.acquire('b')

    const onLost = vi.fn()
    nodeA.startRenewal('a', onLost)
    nodeA.startRenewal('b', onLost)
    expect(nodeA.renewingInstanceIds()).toHaveLength(2)

    nodeA.stopAllRenewals()
    await vi.advanceTimersByTimeAsync(RENEW * 1000 * 3)

    expect(nodeA.renewingInstanceIds()).toEqual([])
    expect(onLost).not.toHaveBeenCalled()
  })
})
