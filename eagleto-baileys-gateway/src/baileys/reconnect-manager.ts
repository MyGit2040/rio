import type { Logger } from 'pino'

import { env } from '../config/env.js'
import { logger } from '../config/logger.js'

/**
 * Reconnect scheduling and the circuit breaker.
 *
 * Reconnecting is not free: WhatsApp reads a number that hammers the handshake
 * as automation, so backoff here is a reputation control, not just a resource
 * one. The two guarantees this module provides are that a failing instance
 * waits progressively longer, and that it eventually stops trying and asks for
 * a human instead of retrying forever.
 */

/**
 * Hand-picked rather than a pure formula: the first two steps are short enough
 * to ride out an ordinary network blip without a visible outage, and the ladder
 * then climbs fast so a genuinely broken session is not retried at speed.
 */
const DELAY_LADDER: readonly number[] = [5, 15, 30, 60, 120]

/** ±20% spread. */
const JITTER_RATIO = 0.2

/**
 * Delay before attempt N (1-based).
 *
 * Jitter matters at fleet scale: a shared upstream failure disconnects every
 * instance at the same instant, and an unjittered ladder would reconnect them
 * all in the same instant too — a self-inflicted thundering herd against the
 * same endpoint that just failed.
 */
export function reconnectDelaySeconds(attempt: number, maxDelaySeconds: number): number {
  const cap = Math.max(1, Math.floor(maxDelaySeconds))
  const n = Math.max(1, Math.floor(Number.isFinite(attempt) ? attempt : 1))
  const lastRung = DELAY_LADDER[DELAY_LADDER.length - 1] ?? 120

  let base: number

  if (n <= DELAY_LADDER.length) {
    base = DELAY_LADDER[n - 1] ?? lastRung
  } else {
    // Past the ladder, keep doubling. The cap below stops this from growing
    // without bound, but the doubling means a long outage backs off hard.
    base = lastRung * 2 ** Math.min(n - DELAY_LADDER.length, 20)
  }

  base = Math.min(base, cap)

  const jittered = base + base * JITTER_RATIO * (Math.random() * 2 - 1)

  // Clamped after jitter so the cap is a true ceiling, never "cap plus 20%".
  return Math.min(cap, Math.max(1, Math.round(jittered)))
}

export interface ReconnectManagerOptions {
  maxAttempts?: number
  windowMinutes?: number
  maxDelaySeconds?: number
  logger?: Logger
}

export class ReconnectManager {
  readonly #timers = new Map<string, NodeJS.Timeout>()
  readonly #options: ReconnectManagerOptions

  constructor(options: ReconnectManagerOptions = {}) {
    this.#options = options
  }

  get maxAttempts(): number {
    return this.#options.maxAttempts ?? env().MAX_RECONNECT_ATTEMPTS
  }

  get windowMinutes(): number {
    return this.#options.windowMinutes ?? env().RECONNECT_WINDOW_MINUTES
  }

  get maxDelaySeconds(): number {
    return this.#options.maxDelaySeconds ?? env().MAX_RECONNECT_DELAY_SECONDS
  }

  #log(): Logger {
    return this.#options.logger ?? logger()
  }

  /** The delay this manager would use for an attempt, without scheduling it. */
  delayFor(attempt: number): number {
    return reconnectDelaySeconds(attempt, this.maxDelaySeconds)
  }

  /**
   * Arm exactly one timer per instance.
   *
   * Replacing any existing timer is the important part: connection-close events
   * can arrive more than once for a single drop, and without this each one
   * would add another timer until the instance reconnected several times in
   * parallel — the tight loop this class exists to prevent.
   */
  schedule(instanceId: string, attempt: number, run: () => Promise<void>): void {
    this.cancel(instanceId)

    const delaySeconds = this.delayFor(attempt)

    const timer = setTimeout(() => {
      // Drop the handle before running: the attempt is no longer pending, and
      // the run may legitimately schedule the next one.
      this.#timers.delete(instanceId)

      void run().catch((error: unknown) => {
        this.#log().error({ instanceId, attempt, err: error }, 'reconnect attempt threw')
      })
    }, delaySeconds * 1000)

    // A pending reconnect must not keep the process alive during shutdown;
    // cancelAll() is the deliberate path, this is the safety net.
    timer.unref?.()

    this.#timers.set(instanceId, timer)

    this.#log().info({ instanceId, attempt, delaySeconds }, 'reconnect scheduled')
  }

  cancel(instanceId: string): void {
    const timer = this.#timers.get(instanceId)

    if (timer) {
      clearTimeout(timer)
      this.#timers.delete(instanceId)
    }
  }

  /** Used by graceful shutdown, so a draining process cannot reopen sockets. */
  cancelAll(): void {
    for (const timer of this.#timers.values()) {
      clearTimeout(timer)
    }

    this.#timers.clear()
  }

  isScheduled(instanceId: string): boolean {
    return this.#timers.has(instanceId)
  }

  pendingCount(): number {
    return this.#timers.size
  }

  /**
   * The breaker: stop retrying once an instance has burned through its attempts
   * inside the window.
   *
   * The window is what keeps the counter honest. An instance that dropped once
   * a week for eight weeks is not a failing instance, and treating its total as
   * a failure count would eventually retire every long-lived number in the
   * fleet. So an elapsed window makes the counter stale and the breaker resets;
   * only a burst inside the window trips it.
   */
  shouldGiveUp(attempts: number, windowStartedAt: Date | null, now: Date = new Date()): boolean {
    if (attempts < this.maxAttempts) {
      return false
    }

    if (windowStartedAt === null) {
      // At the limit with no window recorded: nothing proves the attempts are
      // spread out, so treat them as a burst and stop.
      return true
    }

    const elapsedMinutes = (now.getTime() - windowStartedAt.getTime()) / 60_000

    return elapsedMinutes < this.windowMinutes
  }
}
