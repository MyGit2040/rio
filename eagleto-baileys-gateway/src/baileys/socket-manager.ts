import { env } from '../config/env.js'
import { contextLogger } from '../config/logger.js'
import { prisma } from '../database/client.js'
import { InstanceLock, createRedis } from '../instances/instance-lock.js'
import { InstanceService } from '../instances/instance-service.js'
import { isSendable } from '../instances/instance-state-machine.js'
import { serialQueues } from '../instances/serial-queue.js'
import { proxyAgentFor } from '../instances/proxy.js'
import { enqueueWebhook } from '../webhooks/webhook-dispatcher.js'

import { loadAdapter } from './adapter/index.js'
import type { BaileysAdapter, BaileysSocketHandle, ConnectionUpdate } from './adapter/types.js'
import { openAuthStore, type AuthStoreHandle } from './auth-store.js'
import { registerEventHandlers } from './event-handler.js'
import { ReconnectManager } from './reconnect-manager.js'

/**
 * Owns the live Baileys sockets — exactly one per WhatsApp account, for the
 * lifetime of the process.
 *
 * Three invariants this class exists to hold:
 *  1. One socket per instance. Sockets are reused for every send; they are
 *     never recreated per message or per API call.
 *  2. One owner per instance across the fleet, enforced by a Redis lease. If
 *     the lease is lost the socket closes immediately rather than competing
 *     with another node for the same session.
 *  3. Sending is gated on READY *plus* a stabilization window, so a freshly
 *     scanned QR does not immediately absorb a queued campaign.
 */

interface LiveSocket {
  instanceId: string
  socket: BaileysSocketHandle
  adapter: BaileysAdapter
  auth: AuthStoreHandle
  stabilizationTimer?: NodeJS.Timeout
  closing: boolean
}

export class SocketManager {
  private readonly sockets = new Map<string, LiveSocket>()
  private readonly starting = new Map<string, Promise<void>>()
  private readonly instances = new InstanceService()
  private readonly reconnects = new ReconnectManager()
  private lock: InstanceLock | null = null
  private shuttingDown = false

  private ownership(): InstanceLock {
    if (!this.lock) {
      this.lock = new InstanceLock(createRedis())
    }

    return this.lock
  }

  isLive(instanceId: string): boolean {
    return this.sockets.has(instanceId)
  }

  liveCount(): number {
    return this.sockets.size
  }

  /**
   * Start a socket. Safe to call repeatedly: concurrent callers share the same
   * in-flight promise, and an already-live instance is a no-op. Without this
   * de-duplication, two API calls arriving together would open two sockets for
   * one number.
   */
  async start(instanceId: string): Promise<void> {
    if (this.shuttingDown) {
      throw new Error('Gateway is shutting down; not starting new sockets.')
    }

    if (this.sockets.has(instanceId)) {
      return
    }

    const inFlight = this.starting.get(instanceId)

    if (inFlight) {
      return inFlight
    }

    const task = this.doStart(instanceId).finally(() => this.starting.delete(instanceId))
    this.starting.set(instanceId, task)

    return task
  }

  private async doStart(instanceId: string): Promise<void> {
    const log = contextLogger({ instanceId })

    const instance = await this.instances.findById(instanceId)

    if (!instance) {
      throw new Error(`Instance ${instanceId} does not exist.`)
    }

    if (!instance.enabled) {
      throw new Error(`Instance ${instanceId} is disabled; enable it before starting.`)
    }

    // Claim ownership before touching auth state. Two nodes loading the same
    // Signal session concurrently is how sessions get corrupted.
    const acquired = await this.ownership().acquire(instanceId)

    if (!acquired) {
      throw new Error(
        `Instance ${instanceId} is already owned by another gateway node. Refusing to open a second socket.`,
      )
    }

    try {
      await this.instances.transitionTo(instanceId, 'STARTING', { reason: 'Socket start requested' })

      const packageId = (instance.baileysPackage as never) ?? env().BAILEYS_PACKAGE
      const adapter = await loadAdapter(packageId)
      const auth = await openAuthStore(instanceId, packageId, adapter.version)

      const socket = await adapter.createSocket({
        instanceId,
        auth: auth.state,
        agent: await proxyAgentFor(instance),
      })

      const live: LiveSocket = { instanceId, socket, adapter, auth, closing: false }
      this.sockets.set(instanceId, live)

      // Persist on every credentials change. Baileys mutates auth state during
      // normal traffic, not only at login; skipping a write here is how a
      // session silently rots and demands a re-scan days later.
      socket.onCredsUpdate(async () => {
        try {
          await auth.saveCreds()
        } catch (error) {
          log.error({ err: error }, 'Failed to persist WhatsApp credentials')
        }
      })

      socket.onConnectionUpdate((update) => {
        void this.onConnectionUpdate(live, update).catch((error: unknown) => {
          log.error({ err: error }, 'Failure handling connection update')
        })
      })

      registerEventHandlers(live.instanceId, socket, adapter)

      // If ownership lapses, stop immediately — a socket without the lease may
      // be competing with another node.
      this.ownership().startRenewal(instanceId, () => {
        log.error('Lost instance ownership lease; closing socket to avoid a split session.')
        void this.stop(instanceId, 'ownership-lost')
      })

      log.info({ baileysPackage: packageId, version: adapter.version }, 'Socket started')
    } catch (error) {
      await this.ownership().release(instanceId)
      await this.instances.transitionTo(instanceId, 'ERROR', {
        reason: 'Socket start failed',
        errorMessage: error instanceof Error ? error.message : String(error),
      })

      throw error
    }
  }

  private async onConnectionUpdate(live: LiveSocket, update: ConnectionUpdate): Promise<void> {
    const { instanceId } = live
    const log = contextLogger({ instanceId })

    if (update.qr) {
      // Stored, not pushed: Laravel polls the stored QR, so polling can never
      // churn sockets. WhatsApp rotates the QR roughly every 20s.
      const expiresAt = new Date(Date.now() + 20_000)
      await this.instances.recordQr(instanceId, update.qr, expiresAt)
      await enqueueWebhook({
        eventType: 'instance.qr',
        instanceId,
        data: { expires_at: expiresAt.toISOString() },
      })
    }

    // Deliberately no transition for `connecting`. STARTING already means "a
    // socket is opening", and SYNCING is not reachable from it — SYNCING means
    // "authenticated, pulling history". Emitting it here threw on the very
    // first event of every connection, because `connecting` is what Baileys
    // sends before anything else. The throw was caught and logged, so it
    // presented as a QR that never appeared rather than as an error.

    if (update.connection === 'open') {
      await this.instances.transitionTo(instanceId, 'AUTHENTICATED', { reason: 'Socket open' })
      await enqueueWebhook({ eventType: 'instance.authenticated', instanceId, data: {} })

      const user = live.socket.user()

      await prisma.instance.update({
        where: { id: instanceId },
        data: {
          phoneNumber: user?.id?.split(':')[0]?.split('@')[0] ?? null,
          displayName: user?.name ?? undefined,
          lastConnectedAt: new Date(),
          reconnectAttempts: 0,
          lastQr: null,
          pairingCode: null,
        },
      })

      this.scheduleReadyPromotion(live)
    }

    if (update.connection === 'close') {
      await this.onDisconnected(live, update)
    }
  }

  /**
   * Promote to READY only after the socket has stayed open for the
   * stabilization window. A socket that opens and immediately drops would
   * otherwise be handed a batch of campaign messages it cannot deliver.
   */
  private scheduleReadyPromotion(live: LiveSocket): void {
    clearTimeout(live.stabilizationTimer)

    const seconds = env().INSTANCE_STABILIZATION_SECONDS

    live.stabilizationTimer = setTimeout(() => {
      void (async () => {
        // Still the same live socket? A reconnect in the meantime replaces the
        // entry, and promoting a stale one would mark a dead socket sendable.
        if (this.sockets.get(live.instanceId) !== live || live.closing) {
          return
        }

        await this.instances.markReady(live.instanceId)
        await enqueueWebhook({ eventType: 'instance.ready', instanceId: live.instanceId, data: {} })
        contextLogger({ instanceId: live.instanceId }).info(
          { stabilizationSeconds: seconds },
          'Instance promoted to READY',
        )
      })().catch((error: unknown) => {
        contextLogger({ instanceId: live.instanceId }).error({ err: error }, 'Failed to promote instance to READY')
      })
    }, seconds * 1000)
  }

  private async onDisconnected(live: LiveSocket, update: ConnectionUpdate): Promise<void> {
    const { instanceId } = live
    const log = contextLogger({ instanceId })

    clearTimeout(live.stabilizationTimer)
    this.sockets.delete(instanceId)
    serialQueues.close(instanceId, 'Socket disconnected.')

    const classification = live.adapter.classifyDisconnect(update.lastDisconnect?.error)

    // Logged for EVERY disconnect, not only the fatal ones. A recoverable drop
    // used to be silent, so a socket failing to reach WhatsApp at all looked
    // identical to a QR that never rendered: the retry loop was visible but its
    // cause was not. The underlying error is included verbatim because an
    // unclassified transport failure (DNS, egress blocked, TLS) carries no
    // status code to classify.
    log.warn(
      {
        classification: classification.class,
        code: classification.code ?? null,
        reason: classification.reason ?? null,
        recoverable: classification.recoverable,
        cause: (update.lastDisconnect?.error as Error | undefined)?.message ?? null,
      },
      'WhatsApp socket closed',
    )

    await this.instances.markDisconnected(instanceId, classification)

    await enqueueWebhook({
      eventType:
        classification.class === 'logged_out'
          ? 'instance.logged_out'
          : classification.class === 'replaced'
            ? 'instance.replaced'
            : classification.class === 'restricted'
              ? 'instance.restricted'
              : 'instance.disconnected',
      instanceId,
      data: {
        classification: classification.class,
        code: classification.code ?? null,
        reason: classification.reason ?? null,
        recoverable: classification.recoverable,
      },
    })

    if (!classification.recoverable || this.shuttingDown) {
      await this.ownership().release(instanceId)
      log.warn(
        { classification: classification.class, reason: classification.reason },
        'Socket closed for a non-recoverable reason; not reconnecting',
      )

      return
    }

    const current = await this.instances.findById(instanceId)
    const attempts = (current?.reconnectAttempts ?? 0) + 1

    if (this.reconnects.shouldGiveUp(attempts, current?.reconnectWindowStartedAt ?? null)) {
      await this.instances.transitionTo(instanceId, 'ERROR', {
        reason: 'Reconnect circuit breaker tripped',
        errorMessage: `Gave up after ${attempts} reconnect attempts.`,
      })
      await this.ownership().release(instanceId)
      await enqueueWebhook({
        eventType: 'instance.error',
        instanceId,
        data: { reason: 'reconnect_circuit_breaker', attempts },
      })
      log.error({ attempts }, 'Reconnect circuit breaker tripped; manual action required')

      return
    }

    await prisma.instance.update({
      where: { id: instanceId },
      data: {
        reconnectAttempts: attempts,
        reconnectWindowStartedAt: current?.reconnectWindowStartedAt ?? new Date(),
      },
    })

    await this.instances.transitionTo(instanceId, 'RECONNECT_WAIT', {
      reason: `Reconnect attempt ${attempts} scheduled`,
    })
    await enqueueWebhook({ eventType: 'instance.reconnect_wait', instanceId, data: { attempt: attempts } })

    this.reconnects.schedule(instanceId, attempts, async () => {
      // Ownership was retained across the wait, so the restart does not race
      // another node.
      await this.start(instanceId)
    })
  }

  getSocket(instanceId: string): BaileysSocketHandle | undefined {
    return this.sockets.get(instanceId)?.socket
  }

  /**
   * The only sanctioned way to obtain a socket for sending. Enforces the
   * READY + stabilization rule, and never substitutes a different instance —
   * account assignment belongs to Laravel.
   */
  async requireSendableSocket(instanceId: string): Promise<BaileysSocketHandle> {
    const live = this.sockets.get(instanceId)
    const instance = await this.instances.findById(instanceId)

    if (!instance) {
      throw new Error(`Instance ${instanceId} does not exist.`)
    }

    if (!live) {
      throw new Error(`Instance ${instanceId} has no live socket on this node (state: ${instance.state}).`)
    }

    if (!isSendable(instance.state as never, instance.readySince, env().INSTANCE_STABILIZATION_SECONDS)) {
      throw new Error(
        `Instance ${instanceId} is not sendable yet (state: ${instance.state}). ` +
          `A socket must be READY and stable for ${env().INSTANCE_STABILIZATION_SECONDS}s before it accepts sends.`,
      )
    }

    return live.socket
  }

  async stop(instanceId: string, reason = 'stop-requested'): Promise<void> {
    const live = this.sockets.get(instanceId)

    this.reconnects.cancel(instanceId)
    serialQueues.close(instanceId, `Socket stopped (${reason}).`)

    if (live) {
      live.closing = true
      clearTimeout(live.stabilizationTimer)

      try {
        live.socket.end()
      } catch {
        // Already dead — nothing useful to do, and this must not block shutdown.
      }

      this.sockets.delete(instanceId)
    }

    this.ownership().stopRenewal(instanceId)
    await this.ownership().release(instanceId)
  }

  async logout(instanceId: string): Promise<void> {
    const live = this.sockets.get(instanceId)

    if (live) {
      try {
        await live.socket.logout()
      } finally {
        await live.auth.clear()
      }
    }

    await this.stop(instanceId, 'logout')
    await this.instances.transitionTo(instanceId, 'LOGGED_OUT', { reason: 'Logout requested' })
    await enqueueWebhook({ eventType: 'instance.logged_out', instanceId, data: { requested: true } })
  }

  /** Close every socket cleanly. Called by graceful shutdown. */
  async stopAll(): Promise<void> {
    this.shuttingDown = true
    this.reconnects.cancelAll()

    const ids = [...this.sockets.keys()]

    // Flush any pending credential write before the socket goes away, or the
    // last few Signal updates are lost and the session degrades on restart.
    for (const id of ids) {
      const live = this.sockets.get(id)

      if (live) {
        try {
          await live.auth.saveCreds()
        } catch (error) {
          contextLogger({ instanceId: id }).error({ err: error }, 'Failed to flush credentials during shutdown')
        }
      }
    }

    await Promise.allSettled(ids.map((id) => this.stop(id, 'shutdown')))
    serialQueues.closeAll()
  }

  /** Reopen sockets for instances that should be live. Used at boot. */
  async resumeEnabledInstances(): Promise<number> {
    const candidates = await prisma.instance.findMany({
      where: {
        enabled: true,
        state: { in: ['READY', 'AUTHENTICATED', 'SYNCING', 'DISCONNECTED', 'RECONNECT_WAIT'] },
      },
      select: { id: true },
    })

    let resumed = 0

    for (const candidate of candidates) {
      try {
        await this.start(candidate.id)
        resumed += 1
      } catch (error) {
        // One unstartable instance must not stop the rest from coming back.
        contextLogger({ instanceId: candidate.id }).warn(
          { err: error },
          'Could not resume instance at boot',
        )
      }
    }

    return resumed
  }
}

export const socketManager = new SocketManager()
