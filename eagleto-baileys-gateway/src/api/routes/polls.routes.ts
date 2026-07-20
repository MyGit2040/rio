import type { FastifyInstance } from 'fastify'

import { loadAdapter } from '../../baileys/adapter/index.js'
import { acceptSend } from '../../baileys/message-sender.js'
import { recordPollCreation } from '../../baileys/poll-handler.js'
import { BAILEYS_PACKAGES, env, type BaileysPackageId } from '../../config/env.js'
import { prisma } from '../../database/client.js'
import type { MessageMetadata } from '../../types/index.js'
import { describeZodError, sendPollSchema } from '../schemas/index.js'
import { SEND_ERROR_CODES, baseSendRequest, fail, sendErrorResponse } from './messages.routes.js'

/**
 * Poll sending.
 *
 * Polls are a message like any other — they go through `acceptSend` and are
 * transmitted on the instance's serial queue — but they carry an extra
 * obligation: an incoming vote is an encrypted aggregate that can only be
 * resolved against the poll it belongs to. That resolution is keyed on
 * `whatsappPollMessageId`, so a Poll row without one can never have its votes
 * attributed.
 */

function packageFor(stored: string | null): BaileysPackageId {
  // A per-instance override is only honoured if it names a package this build
  // actually knows; anything else falls back rather than failing the send.
  return BAILEYS_PACKAGES.includes(stored as BaileysPackageId)
    ? (stored as BaileysPackageId)
    : env().BAILEYS_PACKAGE
}

export async function pollRoutes(app: FastifyInstance): Promise<void> {
  app.post('/v1/messages/poll', async (request, reply) => {
    const parsed = sendPollSchema.safeParse(request.body)

    if (!parsed.success) {
      return fail(reply, 422, SEND_ERROR_CODES.invalidBody, describeZodError(parsed.error))
    }

    const body = parsed.data

    const instance = await prisma.instance.findUnique({
      where: { id: body.instance_id },
      select: { id: true, baileysPackage: true },
    })

    if (!instance) {
      return fail(
        reply,
        409,
        SEND_ERROR_CODES.notSendable,
        `Instance ${body.instance_id} does not exist. The gateway will not reroute a poll to another number.`,
      )
    }

    // The poll content shape differs between Baileys lines, so it is built by
    // the adapter for the package this instance actually runs on rather than
    // hand-written here.
    const adapter = await loadAdapter(packageFor(instance.baileysPackage))
    const content = adapter.buildPollContent(
      body.question,
      body.options,
      body.selectable_count,
    ) as Record<string, unknown>

    // The Poll row is created BEFORE acceptance so the sender has somewhere to
    // write the transmitted Baileys message the moment it goes out. Creating it
    // afterwards would race the send: a poll can leave the serial queue before
    // this handler resumes, and the creation payload — the thing vote
    // decryption depends on — would have nowhere to land.
    const pollId = await recordPollCreation({
      instanceId: instance.id,
      recipient: body.recipient,
      question: body.question,
      options: body.options,
      selectableCount: body.selectable_count,
      clientMessageId: body.client_message_id,
      // Both are filled in by message-sender.ts once WhatsApp accepts it.
      whatsappPollMessageId: undefined,
      creationPayload: null,
      metadata: body.metadata as MessageMetadata | undefined,
    })

    try {
      const acceptance = await acceptSend({ ...baseSendRequest(body, 'poll', content), pollId })

      /**
       * A duplicate is an idempotent replay of a poll that was already sent.
       * The row just created is redundant in that case — remove it, because
       * vote resolution looks up exactly one row per WhatsApp message and a
       * second candidate would make attribution ambiguous.
       */
      if (acceptance.status === 'duplicate') {
        await prisma.poll.delete({ where: { id: pollId } }).catch(() => undefined)

        const existing = await prisma.poll.findFirst({
          where: { instanceId: acceptance.instanceId, question: body.question, recipient: body.recipient },
          orderBy: { createdAt: 'desc' },
          select: { id: true },
        })

        return reply.status(202).send({
          success: true,
          status: acceptance.status,
          gateway_message_id: acceptance.gatewayMessageId,
          client_message_id: acceptance.clientMessageId ?? null,
          instance_id: acceptance.instanceId,
          gateway_poll_id: existing?.id ?? null,
        })
      }

      return reply.status(202).send({
        success: true,
        status: acceptance.status,
        gateway_message_id: acceptance.gatewayMessageId,
        client_message_id: acceptance.clientMessageId ?? null,
        instance_id: acceptance.instanceId,
        gateway_poll_id: pollId,
      })
    } catch (error) {
      // The send never happened, so the placeholder row would be an orphan that
      // could never receive a vote.
      await prisma.poll.delete({ where: { id: pollId } }).catch(() => undefined)


      const mapped = sendErrorResponse(reply, error)

      if (mapped) {
        return mapped
      }

      throw error
    }
  })
}
