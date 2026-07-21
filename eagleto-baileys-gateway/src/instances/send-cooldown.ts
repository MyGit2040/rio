/**
 * Per-instance send cooldown — the circuit breaker's "halt".
 *
 * When a number's session health collapses (a forbidden close, a burst of
 * disconnects, repeated decrypt failures), continuing to push messages only
 * deepens the hole. A cooldown records "do not send from this number until T",
 * and the socket manager refuses sends while one is active. It expires on its
 * own, so recovery needs no manual intervention, and it is cleared outright when
 * a number is re-linked.
 *
 * In-memory and per instance; `now` is injectable for tests.
 */

export interface Cooldown {
  until: number
  reason: string
}

export class SendCooldownRegistry {
  private readonly cooldowns = new Map<string, Cooldown>()

  /** Start (or extend) a cooldown. A later/earlier end time replaces the current one. */
  set(instanceId: string, durationMs: number, reason: string, now: number = Date.now()): Cooldown {
    const cooldown = { until: now + Math.max(0, durationMs), reason }
    this.cooldowns.set(instanceId, cooldown)

    return cooldown
  }

  /** The active cooldown for an instance, or null if none is in effect. */
  active(instanceId: string, now: number = Date.now()): Cooldown | null {
    const cooldown = this.cooldowns.get(instanceId)

    if (!cooldown) {
      return null
    }

    if (now >= cooldown.until) {
      this.cooldowns.delete(instanceId)

      return null
    }

    return cooldown
  }

  clear(instanceId: string): void {
    this.cooldowns.delete(instanceId)
  }
}

export const sendCooldowns = new SendCooldownRegistry()
