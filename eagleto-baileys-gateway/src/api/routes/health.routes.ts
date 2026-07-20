import type { FastifyInstance } from 'fastify'

import { buildHealthReport, isShuttingDown, livenessOk, readinessOk } from '../../monitoring/health.js'
import type { HealthReport } from '../../types/index.js'
import { requestLogger } from '../middleware/request-context.js'

/**
 * The two probes an orchestrator calls, and the only unauthenticated endpoints
 * the gateway exposes (see AUTH_EXEMPT_PATHS in the auth middleware).
 *
 * They answer deliberately different questions:
 *
 *   /health/live   "is this process alive?"  — restart me if not
 *   /health/ready  "should I get traffic?"   — take me out of rotation if not
 *
 * Conflating them is the classic outage amplifier: if liveness consulted
 * Postgres, one database blip would fail every replica's liveness probe at once
 * and the orchestrator would kill the whole fleet — dropping every WhatsApp
 * socket, which then all reconnect together and look to WhatsApp exactly like
 * the abuse pattern this codebase exists to avoid.
 *
 * The verdicts themselves are NOT computed here. `monitoring/health.ts` owns
 * process state (including the shutdown flag) and the dependency probes; these
 * handlers only choose the status code and shape the body. A second opinion
 * about readiness living in the HTTP layer is a second thing to keep in sync,
 * and the copy that drifts is always the one nobody is looking at.
 */

function serialiseReport(report: HealthReport): Record<string, unknown> {
  return {
    level: report.level,
    node_id: report.nodeId,
    checked_at: report.checkedAt,
    checks: report.checks.map((check) => ({
      name: check.name,
      level: check.level,
      detail: check.detail ?? null,
      value: check.value ?? null,
    })),
  }
}

export async function healthRoutes(app: FastifyInstance): Promise<void> {
  /**
   * Liveness: no database, no Redis, no WhatsApp. Reaching this handler is
   * itself the proof the probe is asking for.
   */
  app.get('/health/live', async (_request, reply) => {
    const alive = livenessOk()

    return reply.status(alive ? 200 : 503).send({
      status: alive ? 'alive' : 'unhealthy',
      // Reported, not acted on: a draining process is still alive and must be
      // allowed to finish. Failing liveness mid-drain earns a SIGKILL and loses
      // the credential flush.
      shutting_down: isShuttingDown(),
    })
  })

  /**
   * Readiness: dependencies reachable and not shutting down.
   *
   * The happy path stays cheap on purpose — this is polled every few seconds,
   * so it runs the two dependency pings and nothing more. The full report is
   * built only when the answer is "no", because that is the one time anyone
   * needs to know *which* check failed, and by then the extra queries are worth
   * far more than they cost.
   */
  app.get('/health/ready', async (request, reply) => {
    const ready = await readinessOk()

    if (ready) {
      return reply.status(200).send({ status: 'ready', ready: true })
    }

    let report: Record<string, unknown> | null = null

    try {
      report = serialiseReport(await buildHealthReport())
    } catch (error) {
      // Never let the explanation change the verdict: the node is already
      // unready, and a failure to describe why must not turn a clean 503 into
      // a 500 the orchestrator has to interpret.
      requestLogger(request).error({ err: error }, 'Could not build the health report for an unready node')
    }

    return reply.status(503).send({
      status: isShuttingDown() ? 'shutting_down' : 'unready',
      ready: false,
      ...(report === null ? {} : report),
    })
  })
}
