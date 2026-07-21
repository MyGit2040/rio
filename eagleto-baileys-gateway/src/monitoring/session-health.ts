import { env } from '../config/env.js'
import type { EnqueueWebhookInput } from '../webhooks/webhook-dispatcher.js'

/**
 * Per-instance session-health risk score (0 = healthy … 100 = critical).
 *
 * WhatsApp signals session distress before it acts: it closes sockets with
 * telling status codes (403 forbidden, 428 connection-closed-under-load, 440
 * connection-replaced), and a degrading session shows up as repeated send
 * failures and decrypt ("Bad MAC") errors. This tracks a *decaying* score per
 * instance — a burst of trouble pushes a number into a warning band, a quiet
 * spell lets it recover — and reports the crossing so Laravel can pause that
 * number's queue before a soft-ban becomes a hard one.
 *
 * The scoring is pure and time-injectable so the decay curve and the band
 * thresholds can be unit-tested without real clocks or a live socket. Emission
 * of the webhook is left to the caller (see `healthWarningWebhook`), which keeps
 * this module free of I/O.
 */

export type HealthBand = 'healthy' | 'warning' | 'critical'

const BAND_RANK: Record<HealthBand, number> = { healthy: 0, warning: 1, critical: 2 }

export interface SessionHealthConfig {
  warnScore: number
  criticalScore: number
  /** Points shed per minute of quiet — how fast a number recovers. */
  decayPerMinute: number
}

const DEFAULT_CONFIG: SessionHealthConfig = {
  warnScore: 50,
  criticalScore: 80,
  decayPerMinute: 5,
}

/**
 * Weight per disconnect status code. Forbidden/logged-out is the most serious
 * (WhatsApp is actively rejecting the account); a replaced connection is a
 * strong signal; a plain closed/lost/timed-out connection is routine load and
 * scores low so ordinary churn never trips the alarm.
 */
function disconnectWeight(code: number | undefined): number {
  switch (code) {
    case 401: // loggedOut on some lines
    case 403: // forbidden / restricted
      return 45
    case 440: // connectionReplaced
      return 25
    case 428: // connectionClosed (precondition)
    case 408: // timedOut
      return 12
    case 503: // unavailableService
      return 8
    default:
      return 10
  }
}

const SEND_FAILURE_WEIGHT = 8
const DECRYPT_FAILURE_WEIGHT = 15
const SEND_SUCCESS_RECOVERY = 4

export interface SessionHealthUpdate {
  score: number
  band: HealthBand
  /** True when this event pushed the number into a higher band than before. */
  escalated: boolean
  /** What triggered the change, for the webhook and diagnostics. */
  reason: string
}

interface Entry {
  score: number
  updatedAt: number
  band: HealthBand
}

export class SessionHealth {
  private readonly entries = new Map<string, Entry>()

  constructor(private readonly overrides: Partial<SessionHealthConfig> = {}) {}

  private config(): SessionHealthConfig {
    let base = DEFAULT_CONFIG

    try {
      const e = env()
      base = {
        warnScore: e.SESSION_HEALTH_WARN_SCORE,
        criticalScore: e.SESSION_HEALTH_CRITICAL_SCORE,
        decayPerMinute: DEFAULT_CONFIG.decayPerMinute,
      }
    } catch {
      // env not loaded yet (early boot / unit test) — defaults are fine.
    }

    return { ...base, ...this.overrides }
  }

  private bandFor(score: number, config: SessionHealthConfig): HealthBand {
    if (score >= config.criticalScore) {
      return 'critical'
    }

    if (score >= config.warnScore) {
      return 'warning'
    }

    return 'healthy'
  }

  /** Current, decayed score for an instance without mutating it. */
  score(instanceId: string, now: number = Date.now()): number {
    return this.decayed(instanceId, now, this.config())
  }

  band(instanceId: string, now: number = Date.now()): HealthBand {
    return this.bandFor(this.score(instanceId, now), this.config())
  }

  private decayed(instanceId: string, now: number, config: SessionHealthConfig): number {
    const entry = this.entries.get(instanceId)

    if (!entry) {
      return 0
    }

    const elapsedMinutes = Math.max(0, (now - entry.updatedAt) / 60_000)
    const decayed = entry.score - elapsedMinutes * config.decayPerMinute

    return Math.max(0, decayed)
  }

  private apply(instanceId: string, weight: number, reason: string, now: number): SessionHealthUpdate {
    const config = this.config()
    const previous = this.entries.get(instanceId)?.band ?? 'healthy'

    const next = Math.max(0, Math.min(100, this.decayed(instanceId, now, config) + weight))
    const band = this.bandFor(next, config)

    this.entries.set(instanceId, { score: next, updatedAt: now, band })

    return {
      score: Math.round(next),
      band,
      escalated: BAND_RANK[band] > BAND_RANK[previous],
      reason,
    }
  }

  recordDisconnect(instanceId: string, code: number | undefined, now: number = Date.now()): SessionHealthUpdate {
    return this.apply(instanceId, disconnectWeight(code), `disconnect_${code ?? 'unknown'}`, now)
  }

  recordSendFailure(instanceId: string, now: number = Date.now()): SessionHealthUpdate {
    return this.apply(instanceId, SEND_FAILURE_WEIGHT, 'send_failed', now)
  }

  recordDecryptFailure(instanceId: string, now: number = Date.now()): SessionHealthUpdate {
    return this.apply(instanceId, DECRYPT_FAILURE_WEIGHT, 'decrypt_failed', now)
  }

  /** A successful send is recovery evidence — nudge the score down (never below 0). */
  recordSendSuccess(instanceId: string, now: number = Date.now()): SessionHealthUpdate {
    return this.apply(instanceId, -SEND_SUCCESS_RECOVERY, 'send_succeeded', now)
  }

  /** Forget an instance entirely — used on logout/stop. */
  clear(instanceId: string): void {
    this.entries.delete(instanceId)
  }
}

export const sessionHealth = new SessionHealth()

/**
 * Build the `gateway.health_warning` webhook input for an escalation. Kept here
 * so the payload shape is defined once, next to the scoring it describes, and
 * emitted by whichever call site observed the escalation.
 */
export function healthWarningWebhook(instanceId: string, update: SessionHealthUpdate): EnqueueWebhookInput {
  return {
    eventType: 'gateway.health_warning',
    instanceId,
    data: {
      risk_score: update.score,
      band: update.band,
      reason: update.reason,
    },
  }
}
