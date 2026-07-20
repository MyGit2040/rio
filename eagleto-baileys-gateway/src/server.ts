import type { FastifyInstance } from 'fastify'

import { buildApp } from './app.js'
import { socketManager } from './baileys/socket-manager.js'
import { assertRuntimeInvariants, loadEnv } from './config/env.js'
import { logger } from './config/logger.js'
import { connectDatabase, disconnectDatabase } from './database/client.js'
import { MaintenanceWorker } from './jobs/maintenance.worker.js'
import { WebhookWorker } from './jobs/webhook.worker.js'
import { setShuttingDown } from './monitoring/health.js'

/**
 * Process entrypoint.
 *
 * Boot is ordered so that a misconfigured gateway fails immediately and loudly
 * rather than starting, accepting traffic, and failing per-request later:
 * environment, then database, then background work, then sockets, then the
 * listener. Nothing accepts HTTP until everything it depends on is up.
 */

/**
 * After readiness flips, wait before closing the listener.
 *
 * Without this the flag is decorative: a load balancer discovers unreadiness by
 * polling, so cutting the listener in the same tick means in-flight requests are
 * refused by a node the balancer still believes is healthy. One poll interval of
 * grace turns a burst of 502s into a clean handover.
 */
const DRAIN_DELAY_MS = 3_000

/**
 * Ceiling on the whole shutdown sequence.
 *
 * Container runtimes send SIGKILL a fixed time after SIGTERM (30s by default).
 * A wedged socket that never finishes closing must not carry the process past
 * that, because being killed mid-shutdown is exactly how a credential flush is
 * lost — the outcome the ordered shutdown exists to prevent. 25s leaves room to
 * exit deliberately before the runtime decides for us.
 */
const SHUTDOWN_TIMEOUT_MS = 25_000

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms))

async function main(): Promise<void> {
  // Before anything else: an invalid environment is a boot failure, not a
  // runtime surprise. loadEnv reports every problem at once.
  const configuration = loadEnv()
  assertRuntimeInvariants(configuration)

  const log = logger()

  log.info(
    {
      nodeId: configuration.APP_NODE_ID,
      nodeEnv: configuration.NODE_ENV,
      baileysPackage: configuration.BAILEYS_PACKAGE,
    },
    'Starting Eagleto Baileys gateway',
  )

  await connectDatabase()
  log.info('Database connected')

  const webhooks = new WebhookWorker()
  webhooks.start()

  // Nonce and media pruning live in the maintenance worker, which owns every
  // periodic housekeeping task. Scheduling a second nonce sweep here would put
  // two owners on the same retention rule, and the copy that drifts is always
  // the one nobody is looking at.
  const maintenance = new MaintenanceWorker()
  maintenance.start()

  /**
   * Reopen sockets for numbers that were live before the restart. Failures are
   * per-instance and already logged by the socket manager — one number that
   * cannot come back must not stop the process from serving the rest.
   *
   * This runs BEFORE the listener opens, so the node is warm before it takes
   * any traffic and a send can never arrive for a session that is still coming
   * back. The cost is startup latency proportional to the number of instances
   * this node owns: a node with many sessions takes correspondingly longer to
   * begin answering, health probes included. If that ever approaches the
   * orchestrator's startup-probe budget, the fix is to raise that budget (or
   * move the resume after listen and accept 409s during warm-up) — not to drop
   * the resume, which is what puts sessions back after a deploy.
   */
  const resumed = await socketManager.resumeEnabledInstances()
  log.info({ resumed }, 'Resumed instances with live sessions')

  const app = await buildApp()

  await app.listen({ port: configuration.PORT, host: '0.0.0.0' })
  log.info({ port: configuration.PORT }, 'Gateway listening')

  installShutdownHandlers(app, webhooks, maintenance)
}

function installShutdownHandlers(
  app: FastifyInstance,
  webhooks: WebhookWorker,
  maintenance: MaintenanceWorker,
): void {
  const log = logger()
  let shuttingDown = false

  const shutdown = async (signal: NodeJS.Signals): Promise<void> => {
    if (shuttingDown) {
      // A second signal is an operator saying "I meant it". Restarting the
      // sequence would only slow it down, so honour the impatience.
      log.warn({ signal }, 'Second shutdown signal received; exiting immediately')
      process.exit(1)
    }

    shuttingDown = true
    log.info({ signal }, 'Shutdown requested')

    const hardStop = setTimeout(() => {
      log.error(
        { timeoutMs: SHUTDOWN_TIMEOUT_MS },
        'Graceful shutdown exceeded its budget; forcing exit. Some credentials may not have been flushed.',
      )
      process.exit(1)
    }, SHUTDOWN_TIMEOUT_MS)

    try {
      // 1. Stop being advertised as ready. Nothing is torn down yet, so
      //    requests already in flight still complete normally.
      setShuttingDown(true)
      log.info('Readiness withdrawn; draining')

      await sleep(DRAIN_DELAY_MS)

      // 2. Stop accepting new connections and finish the ones in progress.
      await app.close()
      log.info('HTTP listener closed')

      // 3. Stop the background workers. Each finishes the pass it is in rather
      //    than abandoning it: an abandoned webhook pass leaves rows claimed but
      //    undelivered for the stall sweep to rescue minutes later, and an
      //    abandoned prune leaves a half-swept table.
      await Promise.all([webhooks.stop(), maintenance.stop()])
      log.info('Background workers stopped')

      // 4. Close the WhatsApp sockets. This flushes pending credential writes
      //    and releases the Redis ownership leases — without it another node
      //    would have to wait out the lease TTL before it could take over, and
      //    the last Signal updates would be lost, degrading the session.
      await socketManager.stopAll()
      log.info('WhatsApp sockets closed and ownership released')

      // 5. Last, because every step above may still need the database.
      await disconnectDatabase()
      log.info('Database disconnected')

      clearTimeout(hardStop)
      log.info('Shutdown complete')
      process.exit(0)
    } catch (error) {
      clearTimeout(hardStop)
      log.error({ err: error }, 'Shutdown failed')
      process.exit(1)
    }
  }

  process.on('SIGTERM', (signal) => {
    void shutdown(signal)
  })

  process.on('SIGINT', (signal) => {
    void shutdown(signal)
  })
}

main().catch((error: unknown) => {
  // The logger may not exist yet if the failure was the environment itself, so
  // this is the one place that falls back to the console — a boot failure that
  // printed nothing would be the worst possible outcome.
  try {
    logger().fatal({ err: error }, 'Gateway failed to start')
  } catch {
    console.error('Gateway failed to start:', error)
  }

  process.exit(1)
})
