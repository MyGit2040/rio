import type { FastifyInstance, FastifyReply } from 'fastify'
import { z } from 'zod'

import { prisma } from '../../database/client.js'
import { WEBHOOK_STATUSES } from '../../webhooks/webhook-dispatcher.js'

/**
 * Operator surface over the webhook queue.
 *
 * Read-only apart from replay. Delivery is the worker's job — an endpoint that
 * delivered inline would bypass the claim and let an operator race a worker
 * into a double delivery.
 */

const ERROR_CODES = {
  invalidQuery: 'invalid_query',
  invalidParams: 'invalid_params',
  notFound: 'not_found',
} as const

function fail(reply: FastifyReply, status: number, code: string, message: string): FastifyReply {
  return reply.status(status).send({ error: { code, message } })
}

/**
 * Query filters arrive lowercase (`?status=dead_letter`) while the database
 * enum is uppercase; normalise before validating so a caller never has to know
 * the storage casing.
 */
const statusFilter = z
  .string()
  .transform((value) => value.trim().toUpperCase())
  .pipe(z.enum(WEBHOOK_STATUSES))

const listQuerySchema = z.object({
  status: statusFilter.optional(),
  limit: z.coerce.number().int().min(1).max(100).default(50),
  cursor: z.string().min(1).optional(),
})

const eventParamsSchema = z.object({
  eventId: z.string().min(1),
})

interface WebhookEventRow {
  id: string
  instanceId: string | null
  eventType: string
  eventVersion: string
  status: string
  attempts: number
  nextAttemptAt: Date
  deliveredAt: Date | null
  lastStatusCode: number | null
  lastError: string | null
  occurredAt: Date
  createdAt: Date
  updatedAt: Date
}

/** Delivery history fields, in the same snake_case vocabulary as the envelope. */
function serialise(event: WebhookEventRow): Record<string, unknown> {
  return {
    event_id: event.id,
    instance_id: event.instanceId,
    event_type: event.eventType,
    event_version: event.eventVersion,
    status: event.status,
    attempts: event.attempts,
    next_attempt_at: event.nextAttemptAt.toISOString(),
    delivered_at: event.deliveredAt?.toISOString() ?? null,
    last_status_code: event.lastStatusCode,
    last_error: event.lastError,
    occurred_at: event.occurredAt.toISOString(),
    created_at: event.createdAt.toISOString(),
    updated_at: event.updatedAt.toISOString(),
  }
}

const LIST_FIELDS = {
  id: true,
  instanceId: true,
  eventType: true,
  eventVersion: true,
  status: true,
  attempts: true,
  nextAttemptAt: true,
  deliveredAt: true,
  lastStatusCode: true,
  lastError: true,
  occurredAt: true,
  createdAt: true,
  updatedAt: true,
} as const

export async function webhookRoutes(app: FastifyInstance): Promise<void> {
  /**
   * Requeue an event. Attempts reset to zero because a dead letter has already
   * spent its budget — without the reset the first failure would immediately
   * dead-letter it again and the replay would be pointless. The previous
   * error and status code are kept as the record of why it needed replaying.
   */
  app.post('/v1/webhooks/:eventId/replay', async (request, reply) => {
    const params = eventParamsSchema.safeParse(request.params)

    if (!params.success) {
      return fail(reply, 400, ERROR_CODES.invalidParams, 'A webhook event id is required.')
    }

    const existing = await prisma.webhookEvent.findUnique({
      where: { id: params.data.eventId },
      select: { id: true },
    })

    if (!existing) {
      return fail(reply, 404, ERROR_CODES.notFound, `No webhook event with id ${params.data.eventId}.`)
    }

    const event = await prisma.webhookEvent.update({
      where: { id: params.data.eventId },
      data: {
        status: 'PENDING',
        attempts: 0,
        nextAttemptAt: new Date(),
        deliveredAt: null,
      },
      select: LIST_FIELDS,
    })

    return reply.status(200).send(serialise(event))
  })

  app.get('/v1/webhooks', async (request, reply) => {
    const query = listQuerySchema.safeParse(request.query)

    if (!query.success) {
      const detail = query.error.issues
        .map((issue) => `${issue.path.join('.') || 'query'}: ${issue.message}`)
        .join('; ')

      return fail(reply, 400, ERROR_CODES.invalidQuery, detail)
    }

    const { status, limit, cursor } = query.data

    const events = await prisma.webhookEvent.findMany({
      where: status ? { status } : {},
      // Newest first is what an operator triaging failures wants; the id is a
      // tiebreak so the cursor cannot skip or repeat rows sharing a timestamp.
      orderBy: [{ createdAt: 'desc' }, { id: 'desc' }],
      take: limit,
      ...(cursor ? { cursor: { id: cursor }, skip: 1 } : {}),
      select: LIST_FIELDS,
    })

    return reply.status(200).send({
      data: events.map(serialise),
      // Only a full page can have more behind it.
      next_cursor: events.length === limit ? (events[events.length - 1]?.id ?? null) : null,
    })
  })

  app.get('/v1/webhooks/:eventId', async (request, reply) => {
    const params = eventParamsSchema.safeParse(request.params)

    if (!params.success) {
      return fail(reply, 400, ERROR_CODES.invalidParams, 'A webhook event id is required.')
    }

    const event = await prisma.webhookEvent.findUnique({
      where: { id: params.data.eventId },
      select: { ...LIST_FIELDS, payload: true, metadata: true },
    })

    if (!event) {
      return fail(reply, 404, ERROR_CODES.notFound, `No webhook event with id ${params.data.eventId}.`)
    }

    return reply.status(200).send({
      ...serialise(event),
      payload: event.payload,
      metadata: event.metadata,
    })
  })
}
