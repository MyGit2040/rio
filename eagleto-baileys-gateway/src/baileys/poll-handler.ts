import { createHash } from 'node:crypto'

import { contextLogger } from '../config/logger.js'
import { prisma } from '../database/client.js'
import type { MessageMetadata } from '../types/index.js'
import { enqueueWebhook } from '../webhooks/webhook-dispatcher.js'

import type { BaileysAdapter } from './adapter/types.js'
import { normaliseJid } from './jid-utils.js'

/**
 * Poll creation and vote resolution.
 *
 * WhatsApp delivers poll votes as encrypted aggregates that are only
 * interpretable against the original poll message, so the creation payload is
 * retained verbatim. Reconstructing it from the question text would not work —
 * the decryption depends on the stored message secret.
 *
 * Votes are also delivered repeatedly (WhatsApp re-sends the running
 * aggregate), so a fingerprint of the resolved selection is used to tell a
 * genuine change from a redundant redelivery. Without it, Laravel would see the
 * same answer over and over and fire the next campaign step each time.
 */

function fingerprint(selectedOptions: string[]): string {
  return createHash('sha256').update(JSON.stringify([...selectedOptions].sort())).digest('hex')
}

export async function recordPollCreation(input: {
  instanceId: string
  recipient: string
  question: string
  options: string[]
  selectableCount: number
  clientMessageId?: string | undefined
  whatsappPollMessageId: string | undefined
  /**
   * The raw Baileys message returned by sendMessage — required to decrypt
   * votes. Null at creation time: the row is written before the send so the
   * sender has a target, and message-sender.ts fills this in on transmission.
   */
  creationPayload: unknown
  metadata?: MessageMetadata | undefined
}): Promise<string> {
  const poll = await prisma.poll.create({
    data: {
      instanceId: input.instanceId,
      recipient: input.recipient,
      question: input.question,
      options: input.options as never,
      selectableCount: input.selectableCount,
      clientMessageId: input.clientMessageId ?? null,
      whatsappPollMessageId: input.whatsappPollMessageId ?? null,
      encPayload: JSON.stringify(input.creationPayload),
      metadata: (input.metadata ?? {}) as never,
    },
  })

  await enqueueWebhook({
    eventType: 'poll.created',
    instanceId: input.instanceId,
    data: {
      gateway_poll_id: poll.id,
      whatsapp_poll_message_id: poll.whatsappPollMessageId,
      question: poll.question,
      options: input.options,
    },
    metadata: input.metadata ?? {},
  })

  return poll.id
}

/**
 * Resolve an incoming poll update and, when the selection actually changed,
 * report it to Laravel.
 */
export async function handlePollUpdate(input: {
  instanceId: string
  adapter: BaileysAdapter
  pollMessageId: string
  voterJid: string
  update: unknown
  meId?: string | undefined
}): Promise<void> {
  const log = contextLogger({ instanceId: input.instanceId })

  const poll = await prisma.poll.findUnique({
    where: { whatsappPollMessageId: input.pollMessageId },
  })

  if (!poll || !poll.encPayload) {
    // A vote on a poll this gateway did not send (or sent before the payload
    // was retained). Nothing can be resolved, so drop it rather than emit a
    // half-populated event Laravel cannot attribute.
    log.debug({ pollMessageId: input.pollMessageId }, 'Poll update for an unknown poll; ignoring')

    return
  }

  const options = poll.options as unknown as string[]
  const creation = JSON.parse(poll.encPayload) as unknown

  const resolved = input.adapter.resolvePollVote({
    update: input.update,
    pollCreation: creation,
    options,
    voterJid: input.voterJid,
    meId: input.meId,
  })

  if (!resolved) {
    log.warn({ pollMessageId: input.pollMessageId }, 'Could not resolve a poll vote')

    return
  }

  const voter = normaliseJid(input.voterJid)
  const print = fingerprint(resolved.selectedOptions)

  const previous = await prisma.pollVote.findFirst({
    where: { pollId: poll.id, voterJid: voter },
    orderBy: { createdAt: 'desc' },
  })

  if (previous && previous.fingerprint === print) {
    // Same selection as last time — a redelivery, not a new answer.
    return
  }

  const changeType = !previous
    ? 'received'
    : resolved.selectedOptions.length === 0
      ? 'removed'
      : 'changed'

  await prisma.pollVote.create({
    data: {
      pollId: poll.id,
      voterJid: voter,
      selectedOptionIndexes: resolved.selectedOptionIndexes as never,
      selectedOptions: resolved.selectedOptions as never,
      changeType,
      fingerprint: print,
    },
  })

  // The creation payload accumulates votes, so persist the updated aggregate
  // or the next update would be folded onto a stale baseline.
  await prisma.poll.update({
    where: { id: poll.id },
    data: { encPayload: JSON.stringify(creation) },
  })

  await enqueueWebhook({
    eventType:
      changeType === 'removed'
        ? 'poll.vote_removed'
        : changeType === 'changed'
          ? 'poll.vote_changed'
          : 'poll.vote_received',
    instanceId: input.instanceId,
    data: {
      gateway_poll_id: poll.id,
      whatsapp_poll_message_id: poll.whatsappPollMessageId,
      voter: voter.split('@')[0] ?? voter,
      voter_jid: voter,
      selected_option_indexes: resolved.selectedOptionIndexes,
      selected_options: resolved.selectedOptions,
    },
    // Carried through from creation so Laravel can tie the answer back to the
    // campaign, contact and step without its own lookup table.
    metadata: (poll.metadata ?? {}) as MessageMetadata,
  })

  log.info(
    { pollId: poll.id, changeType, selected: resolved.selectedOptions.length },
    'Poll vote recorded',
  )
}
