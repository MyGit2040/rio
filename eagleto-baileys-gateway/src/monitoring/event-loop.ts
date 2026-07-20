import { monitorEventLoopDelay, type IntervalHistogram } from 'node:perf_hooks'

/**
 * Event loop delay sampling.
 *
 * Lag is the one symptom that explains an entire class of complaints at once —
 * "messages are slow", "the API times out", "webhooks lag" — because everything
 * in this process shares one loop. A blocked loop is invisible to CPU and
 * memory graphs, so it has to be measured directly.
 *
 * `monitorEventLoopDelay` is sampled by libuv rather than by a JavaScript
 * timer, so it keeps measuring while the loop is blocked. A setInterval-based
 * approximation cannot: the very stall it is meant to detect also delays the
 * measurement, and the lag is under-reported exactly when it matters.
 */

/** Nanoseconds per millisecond — the histogram reports in nanoseconds. */
const NS_PER_MS = 1e6

class EventLoopMonitor {
  #histogram: IntervalHistogram | null = null

  get running(): boolean {
    return this.#histogram !== null
  }

  /**
   * @param resolutionMs sampling interval. 20ms keeps overhead negligible while
   *        still resolving the sub-100ms stalls worth acting on.
   */
  start(resolutionMs = 20): void {
    if (this.#histogram) {
      return
    }

    const histogram = monitorEventLoopDelay({ resolution: resolutionMs })
    histogram.enable()
    this.#histogram = histogram
  }

  stop(): void {
    this.#histogram?.disable()
    this.#histogram = null
  }

  /** Discard accumulated samples. Percentiles otherwise cover all of uptime and stop moving. */
  reset(): void {
    this.#histogram?.reset()
  }

  meanLagMs(): number {
    return this.#toMs(this.#histogram?.mean)
  }

  p99LagMs(): number {
    // percentile() throws on an empty histogram in some Node builds, so the
    // read is guarded rather than trusted.
    try {
      return this.#toMs(this.#histogram?.percentile(99))
    } catch {
      return 0
    }
  }

  /**
   * A never-started or empty histogram reports NaN/Infinity rather than a
   * number. Health checks and metrics both consume this, and neither should
   * have to defend itself — an unmeasured loop reads as zero lag, because
   * "no evidence of a stall" is the honest answer, and callers must never see
   * a probe throw.
   */
  #toMs(nanoseconds: number | undefined): number {
    if (nanoseconds === undefined || !Number.isFinite(nanoseconds) || nanoseconds < 0) {
      return 0
    }

    return nanoseconds / NS_PER_MS
  }
}

export const eventLoopMonitor = new EventLoopMonitor()
