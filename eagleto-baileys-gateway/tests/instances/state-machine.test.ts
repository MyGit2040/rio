import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  ALLOWED_TRANSITIONS,
  DISCONNECT_CODES,
  assertTransition,
  canTransition,
  classifyDisconnectCode,
  isSendable,
} from '../../src/instances/instance-state-machine.js'
import { INSTANCE_STATES, type InstanceState } from '../../src/types/index.js'

function statesThatCanReach(target: InstanceState): InstanceState[] {
  return INSTANCE_STATES.filter((from) => ALLOWED_TRANSITIONS[from].includes(target))
}

describe('ALLOWED_TRANSITIONS', () => {
  it('declares transitions for every state', () => {
    for (const state of INSTANCE_STATES) {
      expect(ALLOWED_TRANSITIONS[state], `${state} has no transition list`).toBeDefined()
    }
  })

  it('allows every declared transition', () => {
    for (const from of INSTANCE_STATES) {
      for (const to of ALLOWED_TRANSITIONS[from]) {
        expect(canTransition(from, to), `${from} -> ${to} should be allowed`).toBe(true)
        expect(() => assertTransition(from, to)).not.toThrow()
      }
    }
  })

  it('lets every state reach ERROR and STOPPED', () => {
    for (const from of INSTANCE_STATES) {
      if (from !== 'ERROR') {
        expect(canTransition(from, 'ERROR'), `${from} -> ERROR`).toBe(true)
      }

      if (from !== 'STOPPED') {
        expect(canTransition(from, 'STOPPED'), `${from} -> STOPPED`).toBe(true)
      }
    }
  })

  it('never declares a target outside the known state list', () => {
    for (const from of INSTANCE_STATES) {
      for (const to of ALLOWED_TRANSITIONS[from]) {
        expect(INSTANCE_STATES).toContain(to)
      }
    }
  })
})

describe('structural invariants', () => {
  it('reaches READY only from SYNCING or AUTHENTICATED', () => {
    expect(statesThatCanReach('READY').sort()).toEqual(['AUTHENTICATED', 'SYNCING'])
  })

  it('reaches RECONNECT_WAIT only from DISCONNECTED', () => {
    expect(statesThatCanReach('RECONNECT_WAIT')).toEqual(['DISCONNECTED'])
  })

  it.each(['LOGGED_OUT', 'REPLACED', 'RESTRICTED'] as const)(
    '%s is terminal: only an explicit restart, error or stop may follow',
    (terminal) => {
      expect([...ALLOWED_TRANSITIONS[terminal]].sort()).toEqual(['ERROR', 'STARTING', 'STOPPED'])
    },
  )

  it.each(['LOGGED_OUT', 'REPLACED', 'RESTRICTED'] as const)('%s cannot silently resume', (terminal) => {
    for (const to of ['READY', 'AUTHENTICATED', 'SYNCING', 'DISCONNECTED', 'RECONNECT_WAIT', 'PAIRING'] as const) {
      expect(canTransition(terminal, to), `${terminal} -> ${to} must be rejected`).toBe(false)
    }
  })

  it('does not let a paused instance shortcut back to READY', () => {
    expect(canTransition('PAUSED', 'READY')).toBe(false)
    expect(canTransition('PAUSED', 'STARTING')).toBe(true)
  })
})

describe('canTransition / assertTransition', () => {
  const illegal: ReadonlyArray<readonly [InstanceState, InstanceState]> = [
    ['CREATED', 'READY'],
    ['CREATED', 'AUTHENTICATED'],
    ['QR_REQUIRED', 'READY'],
    ['DISCONNECTED', 'READY'],
    ['READY', 'RECONNECT_WAIT'],
    ['READY', 'AUTHENTICATED'],
    ['ERROR', 'RECONNECT_WAIT'],
    ['STOPPED', 'READY'],
    ['RECONNECT_WAIT', 'READY'],
    ['SYNCING', 'RECONNECT_WAIT'],
  ]

  it.each(illegal)('rejects %s -> %s', (from, to) => {
    expect(canTransition(from, to)).toBe(false)
    expect(() => assertTransition(from, to)).toThrow()
  })

  it('names both states in the thrown error', () => {
    expect(() => assertTransition('LOGGED_OUT', 'READY')).toThrow(/LOGGED_OUT -> READY/)
  })

  it('lists the legal targets in the thrown error', () => {
    expect(() => assertTransition('LOGGED_OUT', 'READY')).toThrow(/STARTING/)
  })

  it('allows the QR rotation self-loop', () => {
    expect(canTransition('QR_REQUIRED', 'QR_REQUIRED')).toBe(true)
    expect(canTransition('READY', 'READY')).toBe(false)
  })
})

describe('isSendable', () => {
  const readySince = new Date('2026-01-01T10:00:00.000Z')
  const stabilization = 60

  it('is false while the stabilization window has not elapsed', () => {
    const halfway = new Date(readySince.getTime() + 30_000)

    expect(isSendable('READY', readySince, stabilization, halfway)).toBe(false)
  })

  it('is false one second before the window closes', () => {
    const almost = new Date(readySince.getTime() + 59_000)

    expect(isSendable('READY', readySince, stabilization, almost)).toBe(false)
  })

  it('is true once the window has elapsed exactly', () => {
    const exact = new Date(readySince.getTime() + 60_000)

    expect(isSendable('READY', readySince, stabilization, exact)).toBe(true)
  })

  it('is true well after the window', () => {
    const later = new Date(readySince.getTime() + 10 * 60_000)

    expect(isSendable('READY', readySince, stabilization, later)).toBe(true)
  })

  it('is false without a readySince, however long the socket has been up', () => {
    const later = new Date(readySince.getTime() + 60 * 60_000)

    expect(isSendable('READY', null, stabilization, later)).toBe(false)
  })

  it('is false in every state other than READY, even with an elapsed window', () => {
    const later = new Date(readySince.getTime() + 60 * 60_000)

    for (const state of INSTANCE_STATES) {
      if (state === 'READY') {
        continue
      }

      expect(isSendable(state, readySince, stabilization, later), `${state} must not be sendable`).toBe(false)
    }
  })

  it('is false when readySince is in the future (clock skew)', () => {
    const before = new Date(readySince.getTime() - 5_000)

    expect(isSendable('READY', readySince, stabilization, before)).toBe(false)
  })

  it('needs no wait when stabilization is disabled', () => {
    expect(isSendable('READY', readySince, 0, readySince)).toBe(true)
  })

  describe('with the default now', () => {
    afterEach(() => {
      vi.useRealTimers()
    })

    it('reads the current clock when no now is supplied', () => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date(readySince.getTime() + 30_000))

      expect(isSendable('READY', readySince, stabilization)).toBe(false)

      vi.setSystemTime(new Date(readySince.getTime() + 61_000))

      expect(isSendable('READY', readySince, stabilization)).toBe(true)
    })
  })
})

describe('classifyDisconnectCode', () => {
  it('treats a logout as terminal', () => {
    const result = classifyDisconnectCode(DISCONNECT_CODES.loggedOut)

    expect(result.class).toBe('logged_out')
    expect(result.recoverable).toBe(false)
    expect(result.nextState).toBe('LOGGED_OUT')
    expect(result.code).toBe(401)
  })

  it('treats a replaced connection as terminal', () => {
    const result = classifyDisconnectCode(DISCONNECT_CODES.connectionReplaced)

    expect(result.class).toBe('replaced')
    expect(result.recoverable).toBe(false)
    expect(result.nextState).toBe('REPLACED')
  })

  it('treats a forbidden account as restricted, never retried', () => {
    const result = classifyDisconnectCode(DISCONNECT_CODES.forbidden)

    expect(result.class).toBe('restricted')
    expect(result.recoverable).toBe(false)
    expect(result.nextState).toBe('RESTRICTED')
  })

  it('treats a bad session as a fault requiring cleared credentials', () => {
    const result = classifyDisconnectCode(DISCONNECT_CODES.badSession)

    expect(result.class).toBe('bad_session')
    expect(result.recoverable).toBe(false)
    expect(result.nextState).toBe('ERROR')
  })

  it('treats a multidevice mismatch as needing a re-link', () => {
    const result = classifyDisconnectCode(DISCONNECT_CODES.multideviceMismatch)

    expect(result.class).toBe('auth_required')
    expect(result.recoverable).toBe(false)
    expect(result.nextState).toBe('LOGGED_OUT')
  })

  it.each([
    ['connectionClosed', DISCONNECT_CODES.connectionClosed],
    ['timedOut', DISCONNECT_CODES.timedOut],
    ['restartRequired', DISCONNECT_CODES.restartRequired],
    ['unavailableService', DISCONNECT_CODES.unavailableService],
  ] as const)('treats %s as recoverable', (_name, code) => {
    const result = classifyDisconnectCode(code)

    expect(result.class).toBe('reconnect')
    expect(result.recoverable).toBe(true)
    expect(result.nextState).toBe('DISCONNECTED')
  })

  it('treats an unknown code as recoverable but says so', () => {
    const result = classifyDisconnectCode(9_999)

    expect(result.class).toBe('reconnect')
    expect(result.recoverable).toBe(true)
    expect(result.nextState).toBe('DISCONNECTED')
    expect(result.code).toBe(9_999)
    expect(result.reason).toMatch(/unclassified/i)
    expect(result.reason).toContain('9999')
  })

  it('treats a missing code as recoverable but says so', () => {
    const result = classifyDisconnectCode(undefined)

    expect(result.class).toBe('reconnect')
    expect(result.recoverable).toBe(true)
    expect(result.reason).toMatch(/without a disconnect code/i)
  })

  it('carries the socket message into the reason', () => {
    const result = classifyDisconnectCode(DISCONNECT_CODES.connectionClosed, 'stream errored out')

    expect(result.reason).toContain('stream errored out')
  })

  it('only ever marks the reconnect class as recoverable', () => {
    const codes = [...Object.values(DISCONNECT_CODES), 9_999, undefined]

    for (const code of codes) {
      const result = classifyDisconnectCode(code)

      expect(result.recoverable, `code ${String(code)}`).toBe(result.class === 'reconnect')
    }
  })

  it('never routes a non-recoverable close to a state that retries', () => {
    for (const code of Object.values(DISCONNECT_CODES)) {
      const result = classifyDisconnectCode(code)

      if (!result.recoverable) {
        expect(result.nextState).not.toBe('DISCONNECTED')
        expect(result.nextState).not.toBe('RECONNECT_WAIT')
      }
    }
  })
})
