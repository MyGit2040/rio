import type { Instance, Prisma } from '@prisma/client'

import { prisma } from '../database/client.js'
import type { DisconnectClassification, InstanceState } from '../types/index.js'
import { assertTransition } from './instance-state-machine.js'

/**
 * Persistence and lifecycle for instances.
 *
 * Two rules are enforced here rather than left to call sites:
 *  - no state changes without passing the state machine, and
 *  - no state change without an audit row.
 *
 * A WhatsApp number that goes dead is always investigated after the fact, from
 * this trail. A transition that left no event behind is a transition nobody can
 * explain afterwards.
 */

export class InstanceNotFoundError extends Error {
  constructor(readonly instanceId: string) {
    super(`Instance ${instanceId} not found`)
    this.name = 'InstanceNotFoundError'
  }
}

export interface CreateInstanceInput {
  tenantReference: string
  externalInstanceId: string
  displayName?: string | null
  phoneNumber?: string | null
  /** Per-instance override of BAILEYS_PACKAGE. */
  baileysPackage?: string | null
  webhookUrl?: string | null
  enabled?: boolean
}

export interface ListInstancesFilter {
  tenantReference?: string
  state?: InstanceState | InstanceState[]
  enabled?: boolean
  ownerNodeId?: string
  take?: number
  skip?: number
}

export interface TransitionOptions {
  reason?: string
  /** Structured context for the audit row. Diagnostics only, never parsed. */
  detail?: Record<string, unknown>
  errorCode?: string
  errorMessage?: string
}

/**
 * States that invalidate the session identity, as opposed to merely
 * interrupting it. Reaching one clears `readySince` so a re-linked or resumed
 * number must serve a fresh stabilization window — see `markReady`.
 */
const CLEARS_READY_SINCE: readonly InstanceState[] = [
  'LOGGED_OUT',
  'REPLACED',
  'RESTRICTED',
  'STOPPED',
  'PAUSED',
  'ERROR',
]

/** States that mean the socket is no longer up. */
const RECORDS_DISCONNECTED_AT: readonly InstanceState[] = [
  'DISCONNECTED',
  'LOGGED_OUT',
  'REPLACED',
  'RESTRICTED',
  'ERROR',
  'STOPPED',
]

export class InstanceService {
  async create(input: CreateInstanceInput): Promise<Instance> {
    const instance = await prisma.instance.create({
      data: {
        tenantReference: input.tenantReference,
        externalInstanceId: input.externalInstanceId,
        displayName: input.displayName ?? null,
        phoneNumber: input.phoneNumber ?? null,
        baileysPackage: input.baileysPackage ?? null,
        webhookUrl: input.webhookUrl ?? null,
        enabled: input.enabled ?? true,
        state: 'CREATED',
      },
    })

    await prisma.instanceEvent.create({
      data: {
        instanceId: instance.id,
        type: 'instance.created',
        toState: 'CREATED',
        reason: 'instance registered',
      },
    })

    return instance
  }

  async findById(id: string): Promise<Instance | null> {
    return prisma.instance.findUnique({ where: { id } })
  }

  async findByExternalId(externalInstanceId: string): Promise<Instance | null> {
    return prisma.instance.findUnique({ where: { externalInstanceId } })
  }

  async list(filter: ListInstancesFilter = {}): Promise<Instance[]> {
    const where: Prisma.InstanceWhereInput = {}

    if (filter.tenantReference !== undefined) {
      where.tenantReference = filter.tenantReference
    }

    if (filter.state !== undefined) {
      where.state = Array.isArray(filter.state) ? { in: filter.state } : filter.state
    }

    if (filter.enabled !== undefined) {
      where.enabled = filter.enabled
    }

    if (filter.ownerNodeId !== undefined) {
      where.ownerNodeId = filter.ownerNodeId
    }

    return prisma.instance.findMany({
      where,
      orderBy: { createdAt: 'desc' },
      ...(filter.take !== undefined ? { take: filter.take } : {}),
      ...(filter.skip !== undefined ? { skip: filter.skip } : {}),
    })
  }

  /**
   * The only sanctioned way to change state. Validates against the graph,
   * persists the state plus its side effects, and writes the audit row in the
   * same transaction — a state change whose event failed to write would be
   * worse than no change at all, because it would be invisible.
   */
  async transitionTo(instanceId: string, to: InstanceState, opts: TransitionOptions = {}): Promise<Instance> {
    return this.#transition(instanceId, to, opts, {})
  }

  async #transition(
    instanceId: string,
    to: InstanceState,
    opts: TransitionOptions,
    extra: Prisma.InstanceUpdateInput,
  ): Promise<Instance> {
    return prisma.$transaction(async (tx) => {
      const current = await tx.instance.findUnique({ where: { id: instanceId } })

      if (!current) {
        throw new InstanceNotFoundError(instanceId)
      }

      const from = current.state as InstanceState

      if (from === to) {
        // Baileys re-emits connection updates freely: the same close or the
        // same QR state can arrive several times. Throwing inside an event
        // handler over a duplicate would take the socket down for a non-event,
        // so a same-state call applies its field updates and records nothing.
        return Object.keys(extra).length === 0
          ? current
          : tx.instance.update({ where: { id: instanceId }, data: extra })
      }

      assertTransition(from, to)

      const data: Prisma.InstanceUpdateInput = {
        ...extra,
        state: to,
      }

      if (opts.errorCode !== undefined) {
        data.lastErrorCode = opts.errorCode
      }

      if (opts.errorMessage !== undefined) {
        data.lastErrorMessage = opts.errorMessage
      }

      if (RECORDS_DISCONNECTED_AT.includes(to)) {
        data.lastDisconnectedAt = new Date()
      }

      if (CLEARS_READY_SINCE.includes(to)) {
        data.readySince = null
      }

      const updated = await tx.instance.update({ where: { id: instanceId }, data })

      await tx.instanceEvent.create({
        data: {
          instanceId,
          type: 'state.transition',
          fromState: from,
          toState: to,
          reason: opts.reason ?? null,
          // Prisma's Json input type does not accept `unknown` members; the
          // payload is diagnostic and never read back as a typed value.
          detail: (opts.detail ?? undefined) as Prisma.InputJsonValue | undefined,
        },
      })

      return updated
    })
  }

  /**
   * Store a freshly issued QR. WhatsApp rotates it every few seconds, so this
   * is a high-frequency write: the same-state path above keeps it from filling
   * the event table with one row per rotation.
   */
  async recordQr(instanceId: string, qr: string, expiresAt: Date): Promise<Instance> {
    return this.#transition(
      instanceId,
      'QR_REQUIRED',
      { reason: 'qr issued' },
      { lastQr: qr, lastQrAt: new Date(), qrExpiresAt: expiresAt },
    )
  }

  async recordPairingCode(instanceId: string, code: string, expiresAt: Date): Promise<Instance> {
    return this.#transition(
      instanceId,
      'PAIRING_CODE_REQUIRED',
      { reason: 'pairing code issued' },
      { pairingCode: code, pairingCodeExpiresAt: expiresAt },
    )
  }

  /**
   * Mark the session live and start the stabilization clock.
   *
   * `readySince` is set only when it is not already set, deliberately. A
   * two-second network blip takes the instance DISCONNECTED -> ... -> READY,
   * and restarting the clock there would impose a fresh stabilization penalty
   * on a session that never actually stopped being warm — campaigns would
   * stall for a minute every time the wifi hiccupped.
   *
   * That preservation is safe only because anything which genuinely invalidates
   * the session (LOGGED_OUT, REPLACED, RESTRICTED, STOPPED, PAUSED, ERROR)
   * clears `readySince` on the way through, so a re-linked number always serves
   * the full window.
   *
   * Reaching READY also ends the reconnect window: the breaker's counters exist
   * to describe a failing session, and this one just succeeded.
   */
  async markReady(instanceId: string): Promise<Instance> {
    const current = await prisma.instance.findUnique({ where: { id: instanceId } })

    if (!current) {
      throw new InstanceNotFoundError(instanceId)
    }

    return this.#transition(
      instanceId,
      'READY',
      { reason: 'connection open and synced' },
      {
        lastConnectedAt: new Date(),
        readySince: current.readySince ?? new Date(),
        reconnectAttempts: 0,
        reconnectAfter: null,
        reconnectWindowStartedAt: null,
      },
    )
  }

  /**
   * Apply a disconnect classification.
   *
   * Recoverable closes advance the breaker counters; unrecoverable ones reset
   * them, because a terminal state will not be retried and stale counters would
   * only mislead the next investigation.
   */
  async markDisconnected(instanceId: string, classification: DisconnectClassification): Promise<Instance> {
    const current = await prisma.instance.findUnique({ where: { id: instanceId } })

    if (!current) {
      throw new InstanceNotFoundError(instanceId)
    }

    // A duplicate close for a drop already recorded must not advance the
    // breaker. Baileys re-emits close events, and double-counting them would
    // retire a healthy number after roughly half its allowed attempts.
    const isDuplicateReport = current.state === classification.nextState

    const extra: Prisma.InstanceUpdateInput = classification.recoverable
      ? isDuplicateReport
        ? {}
        : {
            reconnectAttempts: current.reconnectAttempts + 1,
            reconnectWindowStartedAt: current.reconnectWindowStartedAt ?? new Date(),
          }
      : {
          reconnectAttempts: 0,
          reconnectAfter: null,
          reconnectWindowStartedAt: null,
        }

    return this.#transition(
      instanceId,
      classification.nextState,
      {
        reason: classification.reason ?? `disconnected (${classification.class})`,
        detail: {
          class: classification.class,
          code: classification.code ?? null,
          recoverable: classification.recoverable,
        },
        ...(classification.code !== undefined ? { errorCode: String(classification.code) } : {}),
        ...(classification.reason !== undefined ? { errorMessage: classification.reason } : {}),
      },
      extra,
    )
  }

  /**
   * Disabling pauses immediately so nothing can send. Enabling only clears the
   * flag: bringing a socket up is the socket manager's job, and doing it here
   * would start connections from an HTTP request handler.
   */
  async setEnabled(instanceId: string, enabled: boolean): Promise<Instance> {
    const current = await prisma.instance.findUnique({ where: { id: instanceId } })

    if (!current) {
      throw new InstanceNotFoundError(instanceId)
    }

    if (!enabled && current.state !== 'PAUSED') {
      return this.#transition(instanceId, 'PAUSED', { reason: 'instance disabled' }, { enabled: false })
    }

    return prisma.instance.update({ where: { id: instanceId }, data: { enabled } })
  }

  /** Auth rows, messages, polls and events cascade with the instance. */
  async delete(instanceId: string): Promise<void> {
    await prisma.instance.delete({ where: { id: instanceId } })
  }
}

/**
 * The API-safe projection of an instance.
 *
 * Encrypted columns never leave the process, not even as ciphertext: an
 * envelope in a JSON response is an offline attack target and has no legitimate
 * consumer, since Laravel supplies these values and never needs them back.
 *
 * `lastQr` is deliberately retained — it is the entire point of the QR polling
 * endpoint, and it is short-lived and useless once scanned.
 */
export type PublicInstance = Omit<Instance, 'proxyUsernameEnc' | 'proxyPasswordEnc' | 'webhookSecretEnc'>

export function toPublicInstance(instance: Instance): PublicInstance {
  const { proxyUsernameEnc: _u, proxyPasswordEnc: _p, webhookSecretEnc: _w, ...safe } = instance

  return safe
}
