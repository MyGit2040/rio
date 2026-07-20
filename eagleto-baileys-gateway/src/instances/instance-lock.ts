import { Redis } from 'ioredis'
import type { Logger } from 'pino'

import { env } from '../config/env.js'
import { logger } from '../config/logger.js'

/**
 * Single-owner enforcement for WhatsApp sockets.
 *
 * Two gateway nodes opening the same session is not a degraded state, it is a
 * destructive one: WhatsApp treats the second connection as a takeover, both
 * sockets churn, and the number looks abusive. Postgres cannot express this
 * cheaply enough to check on every renewal, so ownership is a short-lived
 * Redis lease that must be actively held. Whoever stops renewing, stops owning.
 */

/** Key namespace, one lease per instance. */
export function lockKey(instanceId: string): string {
  return `baileys:instance-owner:${instanceId}`
}

/**
 * Compare-and-extend.
 *
 * A bare EXPIRE would extend whatever lease currently exists — including one a
 * *different* node acquired after ours lapsed. That is precisely the situation
 * renewal exists to detect, so the extension must be conditional on the value
 * still being ours. GET-then-EXPIRE from the client is not equivalent: the two
 * round trips are not atomic, and the lease can change hands between them.
 */
export const RENEW_SCRIPT = `
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('EXPIRE', KEYS[1], ARGV[2])
else
  return 0
end
`.trim()

/**
 * Compare-and-delete, for the same reason: a node must never be able to drop a
 * lease it does not hold. Releasing another node's lease would hand its live
 * socket to a third node while the second is still sending.
 */
export const RELEASE_SCRIPT = `
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
else
  return 0
end
`.trim()

/**
 * The narrow Redis surface this module uses.
 *
 * Declared structurally so tests can drive an in-memory double: a lock test
 * that needs a live Redis is a test that gets skipped, and this is the one
 * mechanism in the gateway that must never be untested.
 */
export interface RedisLike {
  set(key: string, value: string, mode: 'EX', seconds: number, condition: 'NX'): Promise<'OK' | null>
  get(key: string): Promise<string | null>
  eval(script: string, numKeys: number, ...args: (string | number)[]): Promise<unknown>
}

export interface InstanceLockOptions {
  /** Defaults to APP_NODE_ID. Overridable so tests can simulate two nodes. */
  nodeId?: string
  ttlSeconds?: number
  renewSeconds?: number
  logger?: Logger
}

export class InstanceLock {
  readonly #redis: RedisLike
  readonly #options: InstanceLockOptions
  readonly #timers = new Map<string, NodeJS.Timeout>()

  constructor(redis: RedisLike, options: InstanceLockOptions = {}) {
    this.#redis = redis
    this.#options = options
  }

  get nodeId(): string {
    return this.#options.nodeId ?? env().APP_NODE_ID
  }

  get ttlSeconds(): number {
    return this.#options.ttlSeconds ?? env().INSTANCE_LOCK_TTL_SECONDS
  }

  get renewSeconds(): number {
    return this.#options.renewSeconds ?? env().INSTANCE_LOCK_RENEW_SECONDS
  }

  #log(): Logger {
    return this.#options.logger ?? logger()
  }

  /**
   * Claim ownership. SET NX EX is the whole mutual exclusion: exactly one node
   * can win, and the TTL guarantees a crashed owner's claim expires instead of
   * stranding the instance forever.
   */
  async acquire(instanceId: string): Promise<boolean> {
    const result = await this.#redis.set(lockKey(instanceId), this.nodeId, 'EX', this.ttlSeconds, 'NX')

    if (result === 'OK') {
      return true
    }

    // NX failed, but the holder may be *us* — a process that restarted inside
    // the TTL would otherwise be locked out of its own instances for up to a
    // minute. Re-entrancy is safe only because APP_NODE_ID is required to be
    // unique per process (see config/env.ts); the compare-and-extend below is
    // what makes the check atomic rather than a read-then-hope.
    return this.renew(instanceId)
  }

  async renew(instanceId: string): Promise<boolean> {
    const result = await this.#redis.eval(RENEW_SCRIPT, 1, lockKey(instanceId), this.nodeId, this.ttlSeconds)

    return result === 1
  }

  async release(instanceId: string): Promise<void> {
    // Stop renewing first, or the next tick fires onLost for a lease we let go
    // on purpose and the caller tears down a socket twice.
    this.stopRenewal(instanceId)

    await this.#redis.eval(RELEASE_SCRIPT, 1, lockKey(instanceId), this.nodeId)
  }

  async isOwned(instanceId: string): Promise<boolean> {
    return (await this.#redis.get(lockKey(instanceId))) === this.nodeId
  }

  /**
   * Hold the lease for as long as the socket is open.
   *
   * `onLost` is the emergency brake: the caller closes the socket immediately.
   * Losing the lease means another node may already be opening this session, so
   * every extra second of sending is a second of two live connections.
   */
  startRenewal(instanceId: string, onLost: () => void): void {
    this.stopRenewal(instanceId)

    const timer = setInterval(() => {
      void this.#renewTick(instanceId, onLost)
    }, this.renewSeconds * 1000)

    // Never hold the process open on a renewal timer; shutdown drains sockets
    // deliberately via stopRenewal/release.
    timer.unref?.()

    this.#timers.set(instanceId, timer)
  }

  async #renewTick(instanceId: string, onLost: () => void): Promise<void> {
    let held: boolean

    try {
      held = await this.renew(instanceId)
    } catch (error) {
      // Unreachable Redis means ownership cannot be *proved*. For an exclusion
      // check, absence of proof is a denial: assume the lease is gone and drop
      // the socket. (The opposite bias — assuming we still own it — is how two
      // nodes end up sending on one number during a partition.)
      this.#log().error(
        { instanceId, err: error, nodeId: this.nodeId },
        'instance lease renewal failed to reach Redis; surrendering ownership',
      )
      held = false
    }

    if (held) {
      return
    }

    this.#log().warn({ instanceId, nodeId: this.nodeId }, 'instance lease lost; closing socket')
    this.stopRenewal(instanceId)

    try {
      onLost()
    } catch (error) {
      this.#log().error({ instanceId, err: error }, 'instance lease onLost handler threw')
    }
  }

  stopRenewal(instanceId: string): void {
    const timer = this.#timers.get(instanceId)

    if (timer) {
      clearInterval(timer)
      this.#timers.delete(instanceId)
    }
  }

  /** Every lease this node is actively renewing — used by graceful shutdown. */
  renewingInstanceIds(): string[] {
    return [...this.#timers.keys()]
  }

  stopAllRenewals(): void {
    for (const timer of this.#timers.values()) {
      clearInterval(timer)
    }

    this.#timers.clear()
  }
}

/**
 * `lazyConnect` is off on purpose: a gateway that cannot reach Redis cannot own
 * anything, so that failure belongs at boot, loudly, not at the first send.
 */
export function createRedis(url?: string): Redis {
  const client = new Redis(url ?? env().REDIS_URL, {
    lazyConnect: false,
    enableReadyCheck: true,
    // Ownership commands must fail fast rather than queue forever. A renewal
    // that hangs is indistinguishable from a renewal that succeeded, and the
    // socket would keep sending on a lease we can no longer prove.
    maxRetriesPerRequest: 3,
    retryStrategy: (times) => Math.min(times * 200, 5_000),
    reconnectOnError: (error) => error.message.includes('READONLY'),
  })

  client.on('error', (error) => {
    logger().error({ err: error }, 'redis connection error')
  })

  return client
}
