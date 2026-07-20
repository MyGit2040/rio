import type { Instance } from '@prisma/client'
import type { FastifyInstance, FastifyReply } from 'fastify'
import QRCode from 'qrcode'

import { socketManager } from '../../baileys/socket-manager.js'
import { prisma } from '../../database/client.js'
import { InstanceNotFoundError, InstanceService, toPublicInstance } from '../../instances/instance-service.js'
import { canTransition } from '../../instances/instance-state-machine.js'
import {
  decryptProxyConfig,
  encryptProxyCredentials,
  redactedProxyUrl,
  testProxy,
  type ProxyConfig,
  type ProxyType,
} from '../../instances/proxy.js'
import { findInstance } from '../../instances/resolve.js'
import type { InstanceState } from '../../types/index.js'
import { requestLogger } from '../middleware/request-context.js'
import {
  createInstanceSchema,
  describeZodError,
  instanceParamsSchema,
  pairingCodeSchema,
  proxySchema,
} from '../schemas/index.js'

/**
 * Instance lifecycle over HTTP.
 *
 * Two rules run through every handler here.
 *
 * First, nothing leaves this file without passing `toPublicInstance`. The
 * Instance row carries encrypted proxy credentials and a webhook secret; those
 * are supplied by Laravel and are never needed back, so returning them — even
 * as ciphertext — would only create an offline attack target.
 *
 * Second, state changes go through the state machine. Where a lifecycle action
 * would be an illegal transition (logging out an instance that never started,
 * pausing one that is already banned) the route answers 409 with the reason,
 * rather than letting `assertTransition` throw and surface as an opaque 500.
 */

const instances = new InstanceService()

const CODES = {
  invalidParams: 'invalid_params',
  invalidBody: 'invalid_body',
  notFound: 'instance_not_found',
  conflict: 'instance_conflict',
  duplicate: 'instance_already_exists',
  noProxy: 'proxy_not_configured',
  qrUnavailable: 'qr_unavailable',
} as const

/**
 * Pairing codes are short-lived at WhatsApp's end and the exact lifetime is not
 * published. Two minutes is the advertised expiry to Laravel so a stale code is
 * visibly stale in the UI; WhatsApp remains the authority on acceptance.
 */
const PAIRING_CODE_TTL_MS = 120_000

function fail(reply: FastifyReply, status: number, code: string, message: string): FastifyReply {
  return reply.status(status).send({ error: { code, message } })
}

/** Prisma's unique-constraint violation, duck-typed — see security/nonce-store.ts. */
function isUniqueViolation(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'code' in error &&
    (error as { code?: unknown }).code === 'P2002'
  )
}

function describeError(error: unknown): string {
  return error instanceof Error ? error.message : String(error)
}

function instanceBody(instance: Instance): Record<string, unknown> {
  return {
    instance: toPublicInstance(instance),
    /** Whether THIS node currently holds the socket — the fleet view is in diagnostics. */
    live: socketManager.isLive(instance.id),
  }
}

/**
 * Resolve `:instanceId` by EITHER identity, or answer 404.
 *
 * The path parameter is whatever Laravel holds, which is the name it generated
 * (`externalInstanceId`) — it never learns the gateway's cuid. Every caller here
 * must therefore work from the RETURNED row's `id`, not from the parameter, or
 * the lookup succeeds and the socket call that follows it addresses nothing.
 */
async function findOr404(reply: FastifyReply, instanceId: string): Promise<Instance | null> {
  const instance = await findInstance(instanceId)

  if (!instance) {
    await fail(reply, 404, CODES.notFound, `No instance with id ${instanceId}.`)

    return null
  }

  return instance
}

export async function instanceRoutes(app: FastifyInstance): Promise<void> {
  /** Parse `:instanceId` once, in one place, with one rule. */
  function params(request: { params: unknown }, reply: FastifyReply): string | null {
    const parsed = instanceParamsSchema.safeParse(request.params)

    if (!parsed.success) {
      void fail(reply, 400, CODES.invalidParams, describeZodError(parsed.error))

      return null
    }

    return parsed.data.instanceId
  }

  // -------------------------------------------------------------------------
  // Registration
  // -------------------------------------------------------------------------

  app.post('/v1/instances', async (request, reply) => {
    const body = createInstanceSchema.safeParse(request.body)

    if (!body.success) {
      return fail(reply, 422, CODES.invalidBody, describeZodError(body.error))
    }

    try {
      const created = await instances.create({
        tenantReference: body.data.tenant_reference,
        externalInstanceId: body.data.external_instance_id,
        displayName: body.data.display_name ?? null,
        phoneNumber: body.data.phone_number ?? null,
        baileysPackage: body.data.baileys_package ?? null,
        webhookUrl: body.data.webhook_url ?? null,
        ...(body.data.enabled === undefined ? {} : { enabled: body.data.enabled }),
      })

      return reply.status(201).send(instanceBody(created))
    } catch (error) {
      if (isUniqueViolation(error)) {
        // externalInstanceId is the join key with Laravel. A second row for the
        // same key would split one WhatsApp account across two sessions.
        return fail(
          reply,
          409,
          CODES.duplicate,
          `An instance already exists for external_instance_id '${body.data.external_instance_id}'.`,
        )
      }

      throw error
    }
  })

  // -------------------------------------------------------------------------
  // Lifecycle
  // -------------------------------------------------------------------------

  app.post('/v1/instances/:instanceId/start', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    try {
      await socketManager.start(instance.id)
    } catch (error) {
      // Every refusal from start() is a "not right now" condition: disabled,
      // shutting down, or owned by another node holding the Redis lease. None
      // of them is a server fault, and all of them are actionable by the caller.
      return fail(reply, 409, CODES.conflict, describeError(error))
    }

    const refreshed = await instances.findById(instance.id)

    // 202: the socket is opening. READY still has to be earned through the
    // handshake and the stabilization window, and arrives as a webhook.
    return reply.status(202).send(refreshed ? instanceBody(refreshed) : { started: true })
  })

  /**
   * QR retrieval — a pure read of the stored payload.
   *
   * This endpoint MUST NOT start, restart or touch a socket. Laravel polls it
   * every 1-3 seconds while a QR is on screen; if polling had any side effect on
   * the socket, the act of watching the QR would churn the very session the user
   * is trying to link, and a long-lived poll from a stale browser tab would keep
   * killing a working number. The socket manager writes each rotation to
   * `lastQr` precisely so that reading is free.
   */
  app.get('/v1/instances/:instanceId/qr', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findInstance(instanceId)

    if (!instance) {
      return fail(reply, 404, CODES.notFound, `No instance with id ${instanceId}.`)
    }

    let image: string | null = null

    if (instance.lastQr !== null) {
      try {
        // The stored payload is the QR's text content; the PNG is derived on
        // demand rather than stored, so a rotation never leaves a stale image.
        const png = await QRCode.toBuffer(instance.lastQr, { type: 'png', margin: 1, width: 512 })
        image = png.toString('base64')
      } catch (error) {
        // A render failure must not hide the payload — a caller can still draw
        // the QR itself from qr_data.
        requestLogger(request).error({ err: error, instanceId }, 'Could not render the stored QR payload')
      }
    }

    return reply.status(200).send({
      instance_id: instance.id,
      status: instance.state,
      qr_data: instance.lastQr,
      qr_image_base64: image,
      // Expiry is reported rather than enforced: WhatsApp rotates roughly every
      // 20s, so between rotations the newest stored code may briefly read as
      // expired. Withholding it would blank the screen mid-scan; the caller
      // compares the timestamp and decides.
      expires_at: instance.qrExpiresAt?.toISOString() ?? null,
    })
  })

  app.post('/v1/instances/:instanceId/pairing-code', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const body = pairingCodeSchema.safeParse(request.body)

    if (!body.success) {
      return fail(reply, 422, CODES.invalidBody, describeZodError(body.error))
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    const socket = socketManager.getSocket(instance.id)

    if (!socket) {
      // Pairing codes are issued by the live connection, not by the database.
      return fail(
        reply,
        409,
        CODES.conflict,
        `Instance ${instanceId} has no live socket on this node. Start it first, then request a pairing code.`,
      )
    }

    if (!canTransition(instance.state as InstanceState, 'PAIRING_CODE_REQUIRED')) {
      return fail(
        reply,
        409,
        CODES.conflict,
        `Instance ${instanceId} is in state ${instance.state} and cannot be asked for a pairing code.`,
      )
    }

    // Baileys expects bare digits; the caller may have sent +971 50 123 4567.
    const digits = body.data.phone_number.replace(/\D/g, '')
    const expiresAt = new Date(Date.now() + PAIRING_CODE_TTL_MS)

    let code: string

    try {
      code = await socket.requestPairingCode(digits)
    } catch (error) {
      return fail(reply, 409, CODES.conflict, `WhatsApp refused the pairing-code request: ${describeError(error)}`)
    }

    const updated = await instances.recordPairingCode(instance.id, code, expiresAt)

    return reply.status(200).send({
      ...instanceBody(updated),
      pairing_code: code,
      expires_at: expiresAt.toISOString(),
    })
  })

  app.get('/v1/instances/:instanceId/status', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    return reply.status(200).send(instanceBody(instance))
  })

  /**
   * Restart: close the socket, mark it stopped, open a fresh one.
   *
   * The explicit STOPPED step is not decoration — STARTING is not a legal
   * transition out of READY (a live session must be observed to end before a new
   * one begins), so without it a restart of a healthy instance would be rejected
   * by the state machine.
   */
  app.post('/v1/instances/:instanceId/restart', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    await socketManager.stop(instance.id, 'restart')

    try {
      await instances.transitionTo(instance.id, 'STOPPED', { reason: 'Restart requested' })
      await socketManager.start(instance.id)
    } catch (error) {
      if (error instanceof InstanceNotFoundError) {
        return fail(reply, 404, CODES.notFound, error.message)
      }

      return fail(reply, 409, CODES.conflict, describeError(error))
    }

    const refreshed = await instances.findById(instance.id)

    return reply.status(202).send(refreshed ? instanceBody(refreshed) : { restarted: true })
  })

  /**
   * Pause: close the socket and clear `enabled`, which blocks acceptSend
   * outright regardless of state.
   *
   * The socket is closed BEFORE the flag flips. The close arrives back as a
   * DISCONNECTED transition, and DISCONNECTED -> PAUSED is legal while
   * PAUSED -> DISCONNECTED is not; doing it the other way round would make the
   * socket's own close event an illegal transition on every pause.
   */
  app.post('/v1/instances/:instanceId/pause', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    await socketManager.stop(instance.id, 'paused')

    let updated: Instance

    if (canTransition(instance.state as InstanceState, 'PAUSED')) {
      updated = await instances.setEnabled(instance.id, false)
    } else {
      // Already terminal (logged out, replaced, restricted): there is no live
      // session to pause, but the operator's intent — do not bring this back —
      // is still recorded, and it is what keeps boot-time resume from picking
      // the instance up later.
      updated = await prisma.instance.update({ where: { id: instance.id }, data: { enabled: false } })
    }

    return reply.status(200).send(instanceBody(updated))
  })

  app.post('/v1/instances/:instanceId/resume', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    await instances.setEnabled(instance.id, true)

    try {
      await socketManager.start(instance.id)
    } catch (error) {
      return fail(reply, 409, CODES.conflict, describeError(error))
    }

    const refreshed = await instances.findById(instance.id)

    return reply.status(202).send(refreshed ? instanceBody(refreshed) : { resumed: true })
  })

  /**
   * Logout: clear WhatsApp auth. This unlinks the number — the next start needs
   * a fresh QR or pairing code, so it is not a heavier restart.
   */
  app.post('/v1/instances/:instanceId/logout', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    if (!canTransition(instance.state as InstanceState, 'LOGGED_OUT')) {
      return fail(
        reply,
        409,
        CODES.conflict,
        `Instance ${instanceId} is in state ${instance.state} and has no session to log out.`,
      )
    }

    await socketManager.logout(instance.id)

    const refreshed = await instances.findById(instance.id)

    return reply.status(200).send(refreshed ? instanceBody(refreshed) : { logged_out: true })
  })

  /**
   * Delete: the socket is closed first, deliberately.
   *
   * Auth rows cascade with the instance, so deleting the row under a live socket
   * would leave it writing credentials to a parent that no longer exists.
   */
  app.delete('/v1/instances/:instanceId', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    await socketManager.stop(instance.id, 'deleted')
    await instances.delete(instance.id)

    // The pre-delete projection: the caller gets to see exactly what went.
    return reply.status(200).send({ ...instanceBody(instance), deleted: true, live: false })
  })

  // -------------------------------------------------------------------------
  // Proxy
  // -------------------------------------------------------------------------

  /**
   * A proxy is read when the socket dials, so a change here binds on the NEXT
   * start. Rewiring a live session's egress mid-flight would change the
   * connection's apparent origin under WhatsApp — which is both unreliable and
   * exactly the behaviour this gateway does not implement.
   */
  app.put('/v1/instances/:instanceId/proxy', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const body = proxySchema.safeParse(request.body)

    if (!body.success) {
      return fail(reply, 422, CODES.invalidBody, describeZodError(body.error))
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    const credentials = encryptProxyCredentials({
      username: body.data.username ?? null,
      password: body.data.password ?? null,
    })

    const updated = await prisma.instance.update({
      where: { id: instance.id },
      data: {
        proxyEnabled: true,
        proxyType: body.data.type,
        proxyHost: body.data.host,
        proxyPort: body.data.port,
        ...credentials,
      },
    })

    return reply.status(200).send({
      ...instanceBody(updated),
      proxy: redactedProxyUrl({
        type: body.data.type,
        host: body.data.host,
        port: body.data.port,
        username: body.data.username ?? null,
        password: body.data.password ?? null,
      }),
      applies_on_next_start: true,
    })
  })

  app.delete('/v1/instances/:instanceId/proxy', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    const updated = await prisma.instance.update({
      where: { id: instance.id },
      data: {
        proxyEnabled: false,
        proxyType: null,
        proxyHost: null,
        proxyPort: null,
        proxyUsernameEnc: null,
        proxyPasswordEnc: null,
      },
    })

    return reply.status(200).send({ ...instanceBody(updated), proxy: null, applies_on_next_start: true })
  })

  /**
   * Test a proxy before a session depends on it.
   *
   * With a body, the supplied configuration is tested (check before you save).
   * Without one, the stored configuration is tested. The result is reported
   * separately from any WhatsApp outcome: "the proxy is unreachable" and
   * "WhatsApp rejected this account" need entirely different responses.
   */
  app.post('/v1/instances/:instanceId/proxy/test', async (request, reply) => {
    const instanceId = params(request, reply)

    if (instanceId === null) {
      return reply
    }

    const instance = await findOr404(reply, instanceId)

    if (!instance) {
      return reply
    }

    let config: ProxyConfig | null

    if (request.body === null || request.body === undefined) {
      config = decryptProxyConfig(instance)
    } else {
      const body = proxySchema.safeParse(request.body)

      if (!body.success) {
        return fail(reply, 422, CODES.invalidBody, describeZodError(body.error))
      }

      config = {
        type: body.data.type as ProxyType,
        host: body.data.host,
        port: body.data.port,
        username: body.data.username ?? null,
        password: body.data.password ?? null,
      }
    }

    if (!config) {
      return fail(
        reply,
        400,
        CODES.noProxy,
        `Instance ${instanceId} has no stored proxy. Send a proxy configuration in the body to test one.`,
      )
    }

    const result = await testProxy(config)

    return reply.status(200).send({
      // The gateway's own id, as every other endpoint reports it — the caller
      // may have addressed this instance by its external name.
      instance_id: instance.id,
      // Redacted: the password was supplied by the caller and must not be
      // reflected back into a log, a browser history or an error report.
      proxy: redactedProxyUrl(config),
      ok: result.ok,
      latency_ms: result.latencyMs ?? null,
      error: result.error ?? null,
    })
  })
}
