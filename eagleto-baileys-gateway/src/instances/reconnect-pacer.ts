/**
 * Post-reconnect send ramp.
 *
 * Firing a queued batch the instant a socket comes back is a strong automation
 * signal: a person who just reconnected does not send twenty messages in the
 * first second. After a number becomes sendable again we open a ramp window;
 * each send during that window is held by an extra pause that starts near the
 * configured maximum and decays linearly to zero as the window elapses, so the
 * number eases back to full rate instead of resuming all at once.
 *
 * State is per instance and in-memory only. A ramp is a soft, best-effort
 * smoothing of the first minute after recovery — losing it on a process restart
 * costs nothing, so there is deliberately no persistence here.
 *
 * `now` is injectable so the decay curve can be tested without real time.
 */

interface Ramp {
  until: number
  windowMs: number
  maxExtraMs: number
}

export class ReconnectPacer {
  private readonly ramps = new Map<string, Ramp>()

  /** Open (or restart) the ramp window for an instance. */
  begin(instanceId: string, windowMs: number, maxExtraMs: number, now: number = Date.now()): void {
    if (windowMs <= 0 || maxExtraMs <= 0) {
      this.ramps.delete(instanceId)

      return
    }

    this.ramps.set(instanceId, { until: now + windowMs, windowMs, maxExtraMs })
  }

  /**
   * Extra milliseconds to wait before the next send, or 0 outside a ramp.
   *
   * The delay is proportional to the fraction of the window still remaining, so
   * it is largest right after recovery and reaches zero as the window closes.
   * An elapsed window is cleared on read to keep the map from growing.
   */
  extraDelayMs(instanceId: string, now: number = Date.now()): number {
    const ramp = this.ramps.get(instanceId)

    if (!ramp) {
      return 0
    }

    if (now >= ramp.until) {
      this.ramps.delete(instanceId)

      return 0
    }

    const remainingFraction = (ramp.until - now) / ramp.windowMs

    return Math.round(ramp.maxExtraMs * remainingFraction)
  }

  /** Drop any ramp for an instance (socket stopped/closed). */
  clear(instanceId: string): void {
    this.ramps.delete(instanceId)
  }

  /** True while a ramp is still in effect — for tests and diagnostics. */
  isRamping(instanceId: string, now: number = Date.now()): boolean {
    const ramp = this.ramps.get(instanceId)

    return ramp !== undefined && now < ramp.until
  }
}

export const reconnectPacer = new ReconnectPacer()
