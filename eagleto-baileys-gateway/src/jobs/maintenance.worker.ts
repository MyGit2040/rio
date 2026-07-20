import type { Logger } from 'pino'

import { pruneMedia } from '../baileys/event-handler.js'
import { env } from '../config/env.js'
import { logger } from '../config/logger.js'
import { buildHealthReport } from '../monitoring/health.js'
import { pruneNonces } from '../security/nonce-store.js'
import type { HealthLevel } from '../types/index.js'
import { enqueueWebhook } from '../webhooks/webhook-dispatcher.js'

/**
 * Periodic housekeeping.
 *
 * Everything here is work that nothing else owns and that nobody notices until
 * it has been skipped for a month: the nonce table grows by a row per
 * authenticated request, expired media files keep their bytes on disk, and a
 * degraded gateway has no way to tell Laravel unless something asks it.
 *
 * The loop is one timer rather than three, because these tasks are cheap,
 * unrelated and none of them is urgent — three timers would be three things to
 * shut down for no benefit.
 */

export interface MaintenanceWorkerOptions {
  /** Gap between passes. Housekeeping, not monitoring — five minutes is ample. */
  intervalMs?: number
  /** Run one pass immediately on start, instead of waiting out the first interval. */
  runOnStart?: boolean
}

const DEFAULTS = {
  intervalMs: 300_000,
  runOnStart: false,
} as const

const LEVEL_RANK: Record<HealthLevel, number> = { ok: 0, warning: 1, critical: 2 }

export class MaintenanceWorker {
  private readonly options: Required<MaintenanceWorkerOptions>
  private readonly log: Logger

  private timer: NodeJS.Timeout | null = null
  private inFlight: Promise<void> | null = null

  /**
   * The health level at the end of the previous pass.
   *
   * This single field is what makes alerting transition-based. Emitting on
   * every pass while a problem persists would push an identical event at
   * Laravel every five minutes for as long as the problem lasts — which
   * during a real incident means burying the events that matter under
   * hundreds of copies of the one already being worked on.
   */
  private lastLevel: HealthLevel = 'ok'

  constructor(options: MaintenanceWorkerOptions = {}) {
    this.options = { ...DEFAULTS, ...options }
    this.log = logger().child({ component: 'maintenance-worker' })
  }

  start(): void {
    if (this.timer) {
      return
    }

    this.timer = setInterval(() => {
      void this.tick()
    }, this.options.intervalMs)

    // Housekeeping must never be the reason the process refuses to exit.
    this.timer.unref?.()

    if (this.options.runOnStart) {
      void this.tick()
    }

    this.log.info({ intervalMs: this.options.intervalMs }, 'Maintenance worker started.')
  }

  /** Stops the timer and waits for a pass already underway, so shutdown never cuts a prune in half. */
  async stop(): Promise<void> {
    if (this.timer) {
      clearInterval(this.timer)
      this.timer = null
    }

    await this.inFlight

    this.log.info('Maintenance worker stopped.')
  }

  private tick(): Promise<void> {
    // A slow pass must not overlap the next tick — two concurrent prunes would
    // race on the same rows to no purpose.
    if (this.inFlight) {
      return this.inFlight
    }

    this.inFlight = this.runOnce().finally(() => {
      this.inFlight = null
    })

    return this.inFlight
  }

  /**
   * One housekeeping pass. Public so a test (or an operator through a console)
   * can drive a pass without waiting on a timer.
   *
   * Each task is isolated: a failing prune must not stop the health check that
   * follows it, since the health check is how anyone finds out the prune failed.
   */
  async runOnce(): Promise<void> {
    await this.pruneExpiredNonces()
    await this.pruneExpiredMedia()
    await this.reportHealthTransition()
  }

  private async pruneExpiredNonces(): Promise<void> {
    try {
      // Twice the skew window, never less. A nonce deleted while its signature
      // is still inside the accepted window becomes replayable again — the
      // prune would quietly undo the replay protection it exists to support.
      const removed = await pruneNonces(env().REQUEST_MAX_SKEW_SECONDS * 2)

      if (removed > 0) {
        this.log.debug({ removed }, 'Pruned expired request nonces.')
      }
    } catch (error) {
      // Error level, not warn: production runs at LOG_LEVEL=error, and a
      // warning here would be invisible on exactly the deployments that matter.
      this.log.error({ err: error }, 'Failed to prune expired request nonces.')
    }
  }

  private async pruneExpiredMedia(): Promise<void> {
    try {
      const removed = await pruneMedia()

      if (removed > 0) {
        this.log.debug({ removed }, 'Pruned expired media files.')
      }
    } catch (error) {
      this.log.error({ err: error }, 'Failed to prune expired media.')
    }
  }

  /**
   * Emit gateway.health_warning only when health gets WORSE.
   *
   * Worsening rather than merely "not ok" so an ok -> warning -> critical
   * escalation is still reported: the second event carries genuinely new
   * information, whereas a repeat of the same level does not.
   *
   * Recovery is logged but not sent. The webhook contract
   * (WEBHOOK_EVENT_TYPES) defines no health-recovered event, and inventing one
   * here would mean shipping an event type Laravel has no handler for.
   */
  private async reportHealthTransition(): Promise<void> {
    try {
      const report = await buildHealthReport()
      const previous = this.lastLevel

      // Recorded before the enqueue so a failed enqueue cannot re-fire the same
      // transition on every subsequent pass.
      this.lastLevel = report.level

      if (LEVEL_RANK[report.level] > LEVEL_RANK[previous]) {
        const failing = report.checks.filter((check) => check.level !== 'ok')

        this.log.error(
          { level: report.level, previousLevel: previous, failing: failing.map((check) => check.name) },
          'Gateway health degraded; notifying Laravel.',
        )

        await enqueueWebhook({
          eventType: 'gateway.health_warning',
          instanceId: null,
          data: {
            level: report.level,
            previous_level: previous,
            node_id: report.nodeId,
            checked_at: report.checkedAt,
            // Only the failing checks: the healthy ones are noise in an alert,
            // and the full report is a request away on the health endpoint.
            failing_checks: failing,
          },
        })

        return
      }

      if (LEVEL_RANK[report.level] < LEVEL_RANK[previous]) {
        this.log.info({ level: report.level, previousLevel: previous }, 'Gateway health recovered.')
      }
    } catch (error) {
      this.log.error({ err: error }, 'Failed to evaluate gateway health.')
    }
  }
}
