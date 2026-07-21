import type { FastifyInstance, FastifyReply } from 'fastify'

import { loadAdapter } from '../../baileys/adapter/index.js'
import { socketManager } from '../../baileys/socket-manager.js'
import { env } from '../../config/env.js'
import { databaseHealthy, prisma } from '../../database/client.js'
import { resolveInstanceId } from '../../instances/resolve.js'
import { serialQueues } from '../../instances/serial-queue.js'
import { buildHealthReport } from '../../monitoring/health.js'
import { sessionHealth } from '../../monitoring/session-health.js'
import type { HealthReport } from '../../types/index.js'
import { describeZodError, instanceParamsSchema } from '../schemas/index.js'

/**
 * Operator diagnostics.
 *
 * Both endpoints are AUTHENTICATED, deliberately — they are not exempted
 * alongside the health probes. A probe answers "restart me / send me traffic";
 * these answer "which numbers are live, what state are they in, what was the
 * last error, which Baileys build is running". That is reconnaissance about a
 * customer's WhatsApp fleet, and it is exactly what an unauthenticated caller
 * should never be able to enumerate.
 */

const RECENT_EVENT_LIMIT = 20

const CODES = {
  invalidParams: 'invalid_params',
  notFound: 'instance_not_found',
} as const

function fail(reply: FastifyReply, status: number, code: string, message: string): FastifyReply {
  return reply.status(status).send({ error: { code, message } })
}

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

export async function diagnosticsRoutes(app: FastifyInstance): Promise<void> {
  /**
   * Fleet view, from the perspective of THIS node.
   *
   * Socket counts are local (a socket is owned by exactly one node), while
   * database counts are fleet-wide. Both are reported side by side on purpose:
   * a large gap between "instances the database calls READY" and "sockets this
   * node holds" is the signature of instances stranded on a node that died
   * without releasing its ownership leases.
   */
  app.get('/v1/diagnostics', async (_request, reply) => {
    const [
      totalInstances,
      readyInstances,
      enabledInstances,
      pendingWebhooks,
      retryingWebhooks,
      deliveringWebhooks,
      deadLetterWebhooks,
      databaseUp,
    ] = await Promise.all([
      prisma.instance.count(),
      prisma.instance.count({ where: { state: 'READY' } }),
      prisma.instance.count({ where: { enabled: true } }),
      prisma.webhookEvent.count({ where: { status: 'PENDING' } }),
      prisma.webhookEvent.count({ where: { status: 'RETRY_WAIT' } }),
      prisma.webhookEvent.count({ where: { status: 'DELIVERING' } }),
      prisma.webhookEvent.count({ where: { status: 'DEAD_LETTER' } }),
      databaseHealthy(),
    ])

    const configuration = env()

    // The selected package is resolved through the adapter loader rather than
    // read from configuration alone, so the reported version is the build that
    // actually loaded — the two differ precisely when something is wrong.
    let baileysVersion: string | null = null
    let baileysError: string | null = null

    try {
      baileysVersion = (await loadAdapter(configuration.BAILEYS_PACKAGE)).version
    } catch (error) {
      baileysError = error instanceof Error ? error.message : String(error)
    }

    let health: Record<string, unknown> | null = null
    let healthError: string | null = null

    try {
      health = serialiseReport(await buildHealthReport())
    } catch (error) {
      // Diagnostics must still answer when the health subsystem cannot — a page
      // that fails whole because one probe failed is a page nobody can use
      // during an incident.
      healthError = error instanceof Error ? error.message : String(error)
    }

    return reply.status(200).send({
      node_id: configuration.APP_NODE_ID,
      generated_at: new Date().toISOString(),

      sockets: {
        live_on_this_node: socketManager.liveCount(),
        send_queue_depth: serialQueues.totalDepth(),
      },

      instances: {
        total: totalInstances,
        ready: readyInstances,
        enabled: enabledInstances,
      },

      webhooks: {
        pending: pendingWebhooks,
        retry_wait: retryingWebhooks,
        delivering: deliveringWebhooks,
        dead_letter: deadLetterWebhooks,
      },

      dependencies: {
        database: databaseUp ? 'up' : 'down',
        // Redis reachability is reported by the health subsystem, which owns
        // that probe; it is not duplicated here.
        health: health,
        health_error: healthError,
      },

      baileys: {
        selected_package: configuration.BAILEYS_PACKAGE,
        version: baileysVersion,
        load_error: baileysError,
        package_switch_allowed: configuration.BAILEYS_ALLOW_PACKAGE_SWITCH,
      },
    })
  })

  /** Everything needed to explain one number's behaviour, in one request. */
  app.get('/v1/instances/:instanceId/diagnostics', async (request, reply) => {
    const params = instanceParamsSchema.safeParse(request.params)

    if (!params.success) {
      return fail(reply, 400, CODES.invalidParams, describeZodError(params.error))
    }

    const { instanceId } = params.data

    // Laravel holds the name it generated, never the gateway's cuid, so the
    // parameter is resolved to the internal id before it keys anything — the
    // credential, event and message lookups below are all foreign keys on it.
    const resolvedId = await resolveInstanceId(instanceId)

    if (resolvedId === null) {
      return fail(reply, 404, CODES.notFound, `No instance with id ${instanceId}.`)
    }

    const instance = await prisma.instance.findUnique({
      where: { id: resolvedId },
      select: {
        id: true,
        externalInstanceId: true,
        tenantReference: true,
        state: true,
        enabled: true,
        phoneNumber: true,
        baileysPackage: true,
        readySince: true,
        lastConnectedAt: true,
        lastDisconnectedAt: true,
        lastErrorCode: true,
        lastErrorMessage: true,
        reconnectAttempts: true,
        reconnectAfter: true,
        reconnectWindowStartedAt: true,
        qrExpiresAt: true,
        pairingCodeExpiresAt: true,
        proxyEnabled: true,
        proxyType: true,
        proxyHost: true,
        proxyPort: true,
        ownerNodeId: true,
      },
    })

    if (!instance) {
      return fail(reply, 404, CODES.notFound, `No instance with id ${instanceId}.`)
    }

    const [credential, events, acceptedMessages, failedMessages] = await Promise.all([
      // The auth write clock. A session whose credentials stopped being
      // persisted looks perfectly healthy right up until it is asked to
      // reconnect, so this is the earliest warning available.
      prisma.authCredential.findUnique({
        where: { instanceId: resolvedId },
        select: { lastWriteAt: true, baileysPackage: true, baileysVersion: true },
      }),
      prisma.instanceEvent.findMany({
        where: { instanceId: resolvedId },
        orderBy: { createdAt: 'desc' },
        take: RECENT_EVENT_LIMIT,
        select: {
          id: true,
          type: true,
          fromState: true,
          toState: true,
          reason: true,
          detail: true,
          createdAt: true,
        },
      }),
      prisma.gatewayMessage.count({ where: { instanceId: resolvedId, status: 'ACCEPTED' } }),
      prisma.gatewayMessage.count({ where: { instanceId: resolvedId, status: 'FAILED' } }),
    ])

    return reply.status(200).send({
      instance_id: instance.id,
      external_instance_id: instance.externalInstanceId,
      tenant_reference: instance.tenantReference,
      node_id: env().APP_NODE_ID,
      generated_at: new Date().toISOString(),

      state: instance.state,
      enabled: instance.enabled,
      phone_number: instance.phoneNumber,
      // Whether this node holds the socket, as opposed to what the database
      // believes about the fleet.
      live_on_this_node: socketManager.isLive(instance.id),
      owner_node_id: instance.ownerNodeId,

      ready_since: instance.readySince?.toISOString() ?? null,
      last_connected_at: instance.lastConnectedAt?.toISOString() ?? null,
      last_disconnected_at: instance.lastDisconnectedAt?.toISOString() ?? null,

      reconnect: {
        attempts: instance.reconnectAttempts,
        next_attempt_at: instance.reconnectAfter?.toISOString() ?? null,
        window_started_at: instance.reconnectWindowStartedAt?.toISOString() ?? null,
      },

      last_error: {
        code: instance.lastErrorCode,
        message: instance.lastErrorMessage,
      },

      credentials: {
        last_write_at: credential?.lastWriteAt.toISOString() ?? null,
        baileys_package: credential?.baileysPackage ?? null,
        baileys_version: credential?.baileysVersion ?? null,
        // A session written by one Baileys line will not open on another; this
        // is where that mismatch becomes visible before it becomes a surprise
        // QR re-scan.
        configured_package: instance.baileysPackage ?? env().BAILEYS_PACKAGE,
      },

      send_queue_depth: serialQueues.depth(instance.id),

      // Live anti-ban signals for this number on this node.
      session_health: {
        risk_score: sessionHealth.score(instance.id),
        band: sessionHealth.band(instance.id),
      },

      messages: {
        awaiting_transmission: acceptedMessages,
        failed: failedMessages,
      },

      challenges: {
        qr_expires_at: instance.qrExpiresAt?.toISOString() ?? null,
        pairing_code_expires_at: instance.pairingCodeExpiresAt?.toISOString() ?? null,
      },

      // Host and port only — the credentials are encrypted at rest and are
      // never returned, in any form.
      proxy: instance.proxyEnabled
        ? { type: instance.proxyType, host: instance.proxyHost, port: instance.proxyPort }
        : null,

      recent_events: events.map((event) => ({
        id: event.id,
        type: event.type,
        from_state: event.fromState,
        to_state: event.toState,
        reason: event.reason,
        detail: event.detail ?? null,
        created_at: event.createdAt.toISOString(),
      })),
    })
  })
}
