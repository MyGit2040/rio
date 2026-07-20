import type { Instance } from '@prisma/client'

import { prisma } from '../database/client.js'

/**
 * Resolve an instance by EITHER identifier.
 *
 * Two identities exist for one WhatsApp account:
 *  - `id`                 — the gateway's own cuid, generated here
 *  - `externalInstanceId` — the name Laravel generated (whatsapp_instances.instance_name)
 *
 * Laravel never learns the gateway's cuid: it creates a device under a name it
 * chose and then addresses every subsequent call by that same name. Routes that
 * looked up by `id` alone therefore 404'd on every call after creation, which
 * presented as "instance_not_found" for a device that had just been created
 * successfully.
 *
 * Accepting both keeps Laravel working while leaving the cuid usable for
 * diagnostics and manual calls. The lookup is ordered id-first because that is
 * the primary key; the fallback costs one indexed query and only on a miss.
 */
export async function findInstance(idOrExternalId: string): Promise<Instance | null> {
  if (!idOrExternalId) {
    return null
  }

  const byId = await prisma.instance.findUnique({ where: { id: idOrExternalId } })

  if (byId) {
    return byId
  }

  return prisma.instance.findUnique({ where: { externalInstanceId: idOrExternalId } })
}

/**
 * Resolve to the gateway's internal id, which is what socket manager, auth
 * store and every foreign key are keyed on. Returns null when unknown.
 */
export async function resolveInstanceId(idOrExternalId: string): Promise<string | null> {
  const instance = await findInstance(idOrExternalId)

  return instance?.id ?? null
}

export class InstanceNotFoundError extends Error {
  constructor(readonly idOrExternalId: string) {
    super(`No instance with id ${idOrExternalId}.`)
    this.name = 'InstanceNotFoundError'
  }
}

/** Resolve or throw — for call sites that cannot proceed without one. */
export async function requireInstance(idOrExternalId: string): Promise<Instance> {
  const instance = await findInstance(idOrExternalId)

  if (!instance) {
    throw new InstanceNotFoundError(idOrExternalId)
  }

  return instance
}
