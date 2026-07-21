import { describe, expect, it } from 'vitest'

import { canTransition } from '../../src/instances/instance-state-machine.js'
import { INSTANCE_STATES, type InstanceState } from '../../src/types/index.js'

/**
 * The state machine and the socket manager have to agree.
 *
 * The unit tests for the transition table prove it is internally consistent,
 * and the socket manager's transitions are wrapped in a catch so a connection
 * update cannot kill the process. Together those two reasonable decisions hid a
 * real bug: the manager emitted STARTING -> SYNCING on Baileys' first event,
 * which the table forbids, so every connection threw on its opening event. It
 * was logged and swallowed, and surfaced only as "the QR never appears".
 *
 * These tests walk the exact sequences the socket manager emits, so a
 * divergence between the two fails here instead of in production.
 */

/** Assert a whole path is walkable, naming the first illegal hop. */
function assertPath(path: readonly InstanceState[]): void {
  for (let i = 0; i < path.length - 1; i++) {
    const from = path[i] as InstanceState
    const to = path[i + 1] as InstanceState

    expect(
      canTransition(from, to),
      `The socket manager performs ${from} -> ${to}, but the state machine forbids it.`,
    ).toBe(true)
  }
}

describe('socket manager lifecycle paths', () => {
  it('walks a first-time QR login', () => {
    // start() -> STARTING, connection.update{qr} -> QR_REQUIRED,
    // connection.update{open} -> AUTHENTICATED, stabilization -> READY.
    assertPath(['CREATED', 'STARTING', 'QR_REQUIRED', 'AUTHENTICATED', 'READY'])
  })

  it('walks a warm restore, where saved credentials mean no QR is issued', () => {
    // This is the restart case: the session is restored from the auth store and
    // goes straight to open without ever showing a QR.
    assertPath(['CREATED', 'STARTING', 'AUTHENTICATED', 'READY'])
  })

  it('walks a pairing-code login', () => {
    assertPath(['CREATED', 'STARTING', 'PAIRING_CODE_REQUIRED', 'PAIRING', 'AUTHENTICATED', 'READY'])
  })

  it('walks a recoverable drop and reconnect', () => {
    // onDisconnected -> DISCONNECTED -> RECONNECT_WAIT, then start() again.
    assertPath(['READY', 'DISCONNECTED', 'RECONNECT_WAIT', 'STARTING', 'AUTHENTICATED', 'READY'])
  })

  it('walks the circuit breaker tripping', () => {
    assertPath(['DISCONNECTED', 'ERROR'])
  })

  it('allows the QR to rotate without leaving QR_REQUIRED', () => {
    // WhatsApp reissues the QR every ~20s and recordQr runs on each one.
    expect(canTransition('QR_REQUIRED', 'QR_REQUIRED')).toBe(true)
  })

  it('does not allow SYNCING straight from STARTING', () => {
    // Pins the actual bug. SYNCING means "authenticated, pulling history"; a
    // socket that is merely connecting has not authenticated anything yet.
    // If this ever becomes legal, the manager's handling should be revisited
    // deliberately rather than by widening the table to silence a throw.
    expect(canTransition('STARTING', 'SYNCING')).toBe(false)
  })

  it('reaches READY only through AUTHENTICATED or SYNCING', () => {
    const reaching = (
      ['CREATED', 'STARTING', 'QR_REQUIRED', 'PAIRING', 'AUTHENTICATED', 'SYNCING', 'DISCONNECTED'] as const
    ).filter((state) => canTransition(state, 'READY'))

    expect(reaching.sort()).toEqual(['AUTHENTICATED', 'SYNCING'])
  })

  it('allows a (re)start from every state, because opening a socket is an action not an event', () => {
    // The bug: a rebuilt container leaves an instance recorded as QR_REQUIRED
    // with no live socket. The next /start moved it QR_REQUIRED -> STARTING,
    // which the graph forbade, so the QR never came back — a 409 instead.
    // Starting must be legal from anywhere except STARTING itself (a no-op the
    // service short-circuits).
    for (const from of INSTANCE_STATES) {
      if (from === 'STARTING') {
        continue
      }

      expect(canTransition(from, 'STARTING'), `${from} -> STARTING must be allowed (restart is always valid)`).toBe(
        true,
      )
    }
  })

  it('specifically permits the QR_REQUIRED -> STARTING restart that produced the 409', () => {
    expect(canTransition('QR_REQUIRED', 'STARTING')).toBe(true)
  })

  it('lets resumeEnabledInstances restart a stale READY/SYNCING/AUTHENTICATED row after a reboot', () => {
    // On boot the socket map is empty but the database still holds the last
    // state. resumeEnabledInstances calls start() for these, which transitions
    // to STARTING — it must not throw.
    for (const from of ['READY', 'AUTHENTICATED', 'SYNCING', 'DISCONNECTED', 'RECONNECT_WAIT'] as const) {
      expect(canTransition(from, 'STARTING'), `${from} -> STARTING on resume`).toBe(true)
    }
  })
})
