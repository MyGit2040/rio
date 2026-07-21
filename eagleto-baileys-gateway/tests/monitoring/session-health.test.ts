import { describe, expect, it } from 'vitest'

import { SessionHealth, healthWarningWebhook } from '../../src/monitoring/session-health.js'

// Explicit config so the tests never depend on a loaded environment.
const config = { warnScore: 50, criticalScore: 80, decayPerMinute: 5 }

describe('SessionHealth scoring', () => {
  it('starts every instance at zero / healthy', () => {
    const health = new SessionHealth(config)
    expect(health.score('a', 0)).toBe(0)
    expect(health.band('a', 0)).toBe('healthy')
  })

  it('weighs a forbidden (403) close far more than a routine drop', () => {
    const forbidden = new SessionHealth(config)
    const routine = new SessionHealth(config)

    forbidden.recordDisconnect('a', 403, 0)
    routine.recordDisconnect('b', 428, 0)

    expect(forbidden.score('a', 0)).toBeGreaterThan(routine.score('b', 0))
  })

  it('escalates into the warning band and reports the crossing once', () => {
    const health = new SessionHealth(config)

    // 403 = 45 → still healthy (below 50), first crossing not yet.
    const first = health.recordDisconnect('a', 403, 0)
    expect(first.band).toBe('healthy')
    expect(first.escalated).toBe(false)

    // A send failure (+8) takes it to 53 → warning; this is the escalation.
    const second = health.recordSendFailure('a', 0)
    expect(second.band).toBe('warning')
    expect(second.escalated).toBe(true)

    // Another failure stays in warning — no repeated escalation.
    const third = health.recordSendFailure('a', 0)
    expect(third.band).toBe('warning')
    expect(third.escalated).toBe(false)
  })

  it('reaches the critical band under sustained forbidden closes', () => {
    const health = new SessionHealth(config)
    health.recordDisconnect('a', 403, 0) // 45
    const update = health.recordDisconnect('a', 403, 0) // 90 → critical
    expect(update.band).toBe('critical')
    expect(update.escalated).toBe(true)
    expect(health.score('a', 0)).toBeGreaterThanOrEqual(80)
  })

  it('caps the score at 100', () => {
    const health = new SessionHealth(config)
    for (let i = 0; i < 20; i++) {
      health.recordDisconnect('a', 403, 0)
    }
    expect(health.score('a', 0)).toBeLessThanOrEqual(100)
  })

  it('decays toward healthy over a quiet spell', () => {
    const health = new SessionHealth(config)
    health.recordDisconnect('a', 403, 0) // 45 at t=0

    // 5 points/minute → after 9 minutes, 45 - 45 = 0.
    expect(health.score('a', 9 * 60_000)).toBe(0)
    // Partway: after 4 minutes, 45 - 20 = 25.
    expect(health.score('a', 4 * 60_000)).toBe(25)
  })

  it('nudges the score down on a successful send but never below zero', () => {
    const health = new SessionHealth(config)
    health.recordSendFailure('a', 0) // 8
    const afterSuccess = health.recordSendSuccess('a', 0) // 8 - 4 = 4
    expect(afterSuccess.score).toBe(4)

    // Many successes floor at 0, never negative.
    for (let i = 0; i < 10; i++) {
      health.recordSendSuccess('a', 0)
    }
    expect(health.score('a', 0)).toBe(0)
  })

  it('forgets an instance on clear()', () => {
    const health = new SessionHealth(config)
    health.recordDisconnect('a', 403, 0)
    health.clear('a')
    expect(health.score('a', 0)).toBe(0)
  })
})

describe('healthWarningWebhook', () => {
  it('builds a gateway.health_warning payload from an escalation', () => {
    const health = new SessionHealth(config)
    health.recordDisconnect('inst-1', 403, 0)
    const update = health.recordDisconnect('inst-1', 403, 0) // critical

    const webhook = healthWarningWebhook('inst-1', update)

    expect(webhook.eventType).toBe('gateway.health_warning')
    expect(webhook.instanceId).toBe('inst-1')
    expect(webhook.data).toMatchObject({
      risk_score: update.score,
      band: 'critical',
    })
    expect(typeof webhook.data.reason).toBe('string')
  })
})
