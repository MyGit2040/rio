import { INSTANCE_STATES, type DisconnectClassification, type InstanceState } from '../types/index.js'

/**
 * The instance lifecycle, as a graph.
 *
 * The value of writing the transitions down is that illegal moves become
 * impossible rather than merely unlikely. Three of them matter enough to be
 * invariants the tests pin directly:
 *
 *  1. READY is reachable only from SYNCING or AUTHENTICATED. READY is the state
 *     that authorises sending, so it must be *earned* through a completed
 *     handshake — never assumed because a socket happened to open.
 *  2. LOGGED_OUT, REPLACED and RESTRICTED are terminal. A number that was
 *     logged out, taken over by another session, or banned must not resume on
 *     its own; only an explicit operator restart (-> STARTING) may revive it.
 *     Automatic retry there is both futile and conspicuous to WhatsApp.
 *  3. RECONNECT_WAIT is reachable only from DISCONNECTED, so the backoff timer
 *     can only ever be armed by an actual socket close.
 */

/**
 * Reachable from anywhere: ERROR records a fault, STOPPED is the operator's
 * off switch. Neither is a resumption, so neither weakens rule 2 above.
 */
const UNIVERSAL_SINKS: readonly InstanceState[] = ['ERROR', 'STOPPED']

/**
 * Also reachable from anywhere: STARTING — the source-side mirror of the
 * universal sinks.
 *
 * Opening a socket is an operator/API action, not a connection event. The
 * strict graph below governs what happens *after* a socket opens
 * (STARTING -> QR_REQUIRED -> AUTHENTICATED -> READY); it must not govern the
 * *decision* to open one. Whenever the gateway holds no live socket for an
 * instance — a fresh process after a restart, a crashed socket, or a QR that
 * was never scanned — a start must be able to (re)open it regardless of the
 * state last written to the database.
 *
 * This does not weaken rule 1: reaching STARTING only re-opens a socket, and
 * STARTING does not list READY as a successor, so READY is still earned through
 * a completed handshake.
 *
 * Without it, an instance recorded as QR_REQUIRED — exactly what a rebuilt
 * container leaves behind — could never be started again: every start threw
 * "Illegal transition QR_REQUIRED -> STARTING" and surfaced as a 409, which is
 * the QR-never-appears symptom. The same trap hit resumeEnabledInstances on
 * boot for any instance previously READY, AUTHENTICATED or SYNCING.
 */
const UNIVERSAL_SOURCE: InstanceState = 'STARTING'

/**
 * Outcomes that invalidate the link itself. Any state that owns (or is
 * negotiating) a connection can land here, because these are discovered from
 * the disconnect code rather than reached by design.
 */
const AUTH_FATAL: readonly InstanceState[] = ['LOGGED_OUT', 'REPLACED', 'RESTRICTED']

/**
 * Transitions excluding the universal sinks, which are appended below so no
 * state can accidentally omit them.
 */
const BASE_TRANSITIONS: Record<InstanceState, readonly InstanceState[]> = {
  // Registered but never started.
  CREATED: ['STARTING', 'PAUSED'],

  // A socket is opening. Which challenge follows depends on whether usable
  // credentials were restored.
  STARTING: ['QR_REQUIRED', 'PAIRING_CODE_REQUIRED', 'AUTHENTICATED', 'DISCONNECTED', 'PAUSED', ...AUTH_FATAL],

  // Self-loop is legal: WhatsApp rotates the QR every few seconds, and each
  // rotation is a genuine repeat of the same state rather than a new one.
  QR_REQUIRED: [
    'QR_REQUIRED',
    'PAIRING',
    'PAIRING_CODE_REQUIRED',
    'AUTHENTICATED',
    'DISCONNECTED',
    'PAUSED',
    ...AUTH_FATAL,
  ],

  PAIRING_CODE_REQUIRED: [
    'PAIRING_CODE_REQUIRED',
    'PAIRING',
    'QR_REQUIRED',
    'AUTHENTICATED',
    'DISCONNECTED',
    'PAUSED',
    ...AUTH_FATAL,
  ],

  // The user acted; the handshake is in flight. It may still expire back to a
  // fresh challenge.
  PAIRING: ['AUTHENTICATED', 'QR_REQUIRED', 'PAIRING_CODE_REQUIRED', 'DISCONNECTED', 'PAUSED', ...AUTH_FATAL],

  // Credentials accepted. Most sessions sync first; a warm restore can be
  // ready immediately.
  AUTHENTICATED: ['SYNCING', 'READY', 'DISCONNECTED', 'PAUSED', ...AUTH_FATAL],

  SYNCING: ['READY', 'DISCONNECTED', 'PAUSED', ...AUTH_FATAL],

  // Note the absence of RECONNECT_WAIT: a live socket must be observed to
  // close (DISCONNECTED) before any backoff is armed.
  READY: ['SYNCING', 'DISCONNECTED', 'PAUSED', ...AUTH_FATAL],

  // STARTING direct is the immediate-retry path (e.g. code 515, which asks for
  // a restart rather than a wait).
  DISCONNECTED: ['RECONNECT_WAIT', 'STARTING', 'PAUSED', ...AUTH_FATAL],

  RECONNECT_WAIT: ['STARTING', 'PAUSED', ...AUTH_FATAL],

  // Pausing closes the socket, so resuming replays the full handshake. That is
  // what keeps rule 1 absolute: there is no PAUSED -> READY shortcut.
  PAUSED: ['STARTING', ...AUTH_FATAL],

  // Terminal. Only an operator restart may follow.
  LOGGED_OUT: ['STARTING'],
  REPLACED: ['STARTING'],
  RESTRICTED: ['STARTING'],

  ERROR: ['STARTING'],
  STOPPED: ['STARTING'],
}

function withUniversalEdges(from: InstanceState, targets: readonly InstanceState[]): readonly InstanceState[] {
  const merged = new Set<InstanceState>(targets)

  for (const sink of UNIVERSAL_SINKS) {
    // A state is not a sink for itself; re-entering ERROR or STOPPED is a
    // no-op, and the service short-circuits same-state calls anyway.
    if (sink !== from) {
      merged.add(sink)
    }
  }

  // Every state except STARTING itself may (re)start. STARTING -> STARTING is a
  // no-op the service short-circuits, so it is left out for the same reason.
  if (from !== UNIVERSAL_SOURCE) {
    merged.add(UNIVERSAL_SOURCE)
  }

  return Object.freeze([...merged])
}

export const ALLOWED_TRANSITIONS: Record<InstanceState, readonly InstanceState[]> = Object.freeze(
  Object.fromEntries(
    INSTANCE_STATES.map((from) => [from, withUniversalEdges(from, BASE_TRANSITIONS[from])] as const),
  ) as Record<InstanceState, readonly InstanceState[]>,
)

export function canTransition(from: InstanceState, to: InstanceState): boolean {
  return ALLOWED_TRANSITIONS[from].includes(to)
}

export function assertTransition(from: InstanceState, to: InstanceState): void {
  if (!canTransition(from, to)) {
    throw new Error(
      `Illegal instance transition ${from} -> ${to}. ` +
        `Allowed from ${from}: ${ALLOWED_TRANSITIONS[from].join(', ')}.`,
    )
  }
}

/**
 * The single authority on whether an instance may send.
 *
 * A connected socket is not a sendable socket. Immediately after a link opens,
 * WhatsApp is still delivering app-state, contacts and chat history; sending
 * into that window is what gets numbers flagged. So sending requires all three
 * of: the READY state, a recorded readySince, and a fully elapsed
 * stabilization window.
 *
 * `readySince` in the future (clock skew, a bad write) yields a negative
 * elapsed time and therefore `false` — the conservative answer.
 */
export function isSendable(
  state: InstanceState,
  readySince: Date | null,
  stabilizationSeconds: number,
  now: Date = new Date(),
): boolean {
  if (state !== 'READY' || readySince === null) {
    return false
  }

  const elapsedMs = now.getTime() - readySince.getTime()

  if (!Number.isFinite(elapsedMs)) {
    return false
  }

  return elapsedMs >= stabilizationSeconds * 1000
}

/**
 * Baileys' documented DisconnectReason codes, named here so the mapping below
 * reads as intent rather than as magic numbers.
 */
export const DISCONNECT_CODES = {
  loggedOut: 401,
  forbidden: 403,
  timedOut: 408,
  multideviceMismatch: 411,
  connectionClosed: 428,
  connectionReplaced: 440,
  badSession: 500,
  unavailableService: 503,
  restartRequired: 515,
} as const

/**
 * Turn a socket-close code into a decision.
 *
 * Only `reconnect` permits an automatic retry. Everything else needs Laravel
 * or a human, because retrying a logout, a takeover or a ban cannot succeed and
 * makes the number look like a bot while it fails.
 *
 * Unknown codes are treated as recoverable *and* labelled as unclassified: a
 * transient blip WhatsApp has not documented should not permanently kill a
 * customer's number, but it must be visible in the event trail so a new code
 * shows up as a pattern instead of hiding as noise.
 */
export function classifyDisconnectCode(code: number | undefined, message?: string): DisconnectClassification {
  const detail = message?.trim() ? ` (${message.trim()})` : ''

  const build = (
    cls: DisconnectClassification['class'],
    recoverable: boolean,
    nextState: InstanceState,
    reason: string,
  ): DisconnectClassification => ({
    class: cls,
    recoverable,
    nextState,
    code,
    reason: `${reason}${detail}`,
  })

  switch (code) {
    case DISCONNECT_CODES.loggedOut:
      // The user unlinked the device from their phone. Only a fresh QR helps.
      return build('logged_out', false, 'LOGGED_OUT', 'logged out from the linked device')

    case DISCONNECT_CODES.forbidden:
      // WhatsApp refused the account: ban or restriction. Retrying is the worst
      // possible response.
      return build('restricted', false, 'RESTRICTED', 'account restricted or banned by WhatsApp')

    case DISCONNECT_CODES.connectionReplaced:
      // Another session took the socket. Reconnecting would fight it and churn
      // both sides.
      return build('replaced', false, 'REPLACED', 'connection replaced by another session')

    case DISCONNECT_CODES.multideviceMismatch:
      // Stored credentials no longer match the multi-device format in use, so
      // the link must be re-established rather than retried.
      return build('auth_required', false, 'LOGGED_OUT', 'multi-device mismatch, re-link required')

    case DISCONNECT_CODES.badSession:
      // Local session state is corrupt. This is our fault domain, not the
      // user's, so it lands in ERROR — but auth must be cleared before a
      // restart can succeed.
      return build('bad_session', false, 'ERROR', 'bad session state, credentials must be cleared')

    case DISCONNECT_CODES.connectionClosed:
      return build('reconnect', true, 'DISCONNECTED', 'connection closed')

    case DISCONNECT_CODES.timedOut:
      return build('reconnect', true, 'DISCONNECTED', 'connection lost or timed out')

    case DISCONNECT_CODES.restartRequired:
      // Routine: Baileys asks for a socket restart after pairing completes.
      return build('reconnect', true, 'DISCONNECTED', 'restart required')

    case DISCONNECT_CODES.unavailableService:
      return build('reconnect', true, 'DISCONNECTED', 'service temporarily unavailable')

    case undefined:
      return build('reconnect', true, 'DISCONNECTED', 'socket closed without a disconnect code')

    default:
      return build('reconnect', true, 'DISCONNECTED', `unclassified disconnect code ${code}, treated as transient`)
  }
}
