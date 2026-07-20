import type { Instance } from '@prisma/client'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Instance identity resolution.
 *
 * One WhatsApp account carries two identifiers: the gateway's own cuid (`id`)
 * and the name Laravel generated (`externalInstanceId`). Laravel never learns
 * the cuid — it registers a device under a name it chose and then addresses
 * every later call by that same name — so any lookup keyed on `id` alone answers
 * "no instance with id demo-je7gydjz" for a device that exists and is running.
 *
 * These tests hold the contract that closes that gap: either identifier resolves
 * to the same row, and what comes back is always the INTERNAL id, because that
 * is what every foreign key, the serial queue and the socket manager are keyed
 * on.
 */

// Hoisted so the mock factory (which vitest lifts above the imports) and the
// assertions below share one object. Prisma is doubled rather than reached:
// resolution is pure lookup logic and must be provable without a database.
const { prismaMock } = vi.hoisted(() => ({
  prismaMock: {
    instance: {
      findUnique: vi.fn(),
    },
  },
}))

vi.mock('../../src/database/client.js', () => ({ prisma: prismaMock }))

const { InstanceNotFoundError, findInstance, requireInstance, resolveInstanceId } = await import(
  '../../src/instances/resolve.js'
)

/** The gateway's id: a cuid nobody outside this process ever sees. */
const INTERNAL_ID = 'clz9k2m4x0000qwer1234asdf'

/** Laravel's id: whatsapp_instances.instance_name, and all it ever holds. */
const EXTERNAL_ID = 'demo-je7gydjz'

const ROW = {
  id: INTERNAL_ID,
  externalInstanceId: EXTERNAL_ID,
  tenantReference: 'tenant-7',
  state: 'READY',
  enabled: true,
} as unknown as Instance

/**
 * Stand in for the unique indexes on `id` and `externalInstanceId`: the double
 * answers whichever key it is asked by, and nothing else.
 */
function withStoredInstance(row: Instance = ROW): void {
  prismaMock.instance.findUnique.mockImplementation(
    async (args: { where: { id?: string; externalInstanceId?: string } }) => {
      if (args.where.id !== undefined) {
        return args.where.id === row.id ? row : null
      }

      if (args.where.externalInstanceId !== undefined) {
        return args.where.externalInstanceId === row.externalInstanceId ? row : null
      }

      return null
    },
  )
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('findInstance', () => {
  it('finds an instance by the gateway id', async () => {
    withStoredInstance()

    await expect(findInstance(INTERNAL_ID)).resolves.toMatchObject({ id: INTERNAL_ID })
  })

  it('finds the same instance by the external name Laravel generated', async () => {
    withStoredInstance()

    await expect(findInstance(EXTERNAL_ID)).resolves.toMatchObject({ id: INTERNAL_ID })
  })

  it('tries the primary key first and only falls back on a miss', async () => {
    withStoredInstance()

    await findInstance(INTERNAL_ID)

    // A hit on the id must not cost a second query — this is the common path
    // for every internal caller.
    expect(prismaMock.instance.findUnique).toHaveBeenCalledTimes(1)
    expect(prismaMock.instance.findUnique).toHaveBeenCalledWith({ where: { id: INTERNAL_ID } })

    vi.clearAllMocks()

    await findInstance(EXTERNAL_ID)

    expect(prismaMock.instance.findUnique).toHaveBeenCalledTimes(2)
    expect(prismaMock.instance.findUnique).toHaveBeenNthCalledWith(2, {
      where: { externalInstanceId: EXTERNAL_ID },
    })
  })

  it('returns null for an identifier that matches neither column', async () => {
    withStoredInstance()

    await expect(findInstance('no-such-instance')).resolves.toBeNull()
  })

  it('returns null for an empty identifier without querying at all', async () => {
    withStoredInstance()

    await expect(findInstance('')).resolves.toBeNull()
    expect(prismaMock.instance.findUnique).not.toHaveBeenCalled()
  })
})

describe('resolveInstanceId', () => {
  /**
   * The regression this whole module exists for.
   *
   * Laravel sends `demo-je7gydjz`. What has to come back is the cuid — anything
   * that hands the external name onward produces a foreign key insert against a
   * row that does not exist, or a socket lookup for an instance nothing is
   * holding.
   */
  it('maps an external name to the internal id', async () => {
    withStoredInstance()

    await expect(resolveInstanceId(EXTERNAL_ID)).resolves.toBe(INTERNAL_ID)
  })

  it('is idempotent: an internal id resolves to itself', async () => {
    withStoredInstance()

    await expect(resolveInstanceId(INTERNAL_ID)).resolves.toBe(INTERNAL_ID)
  })

  it('returns null rather than echoing an unknown identifier back', async () => {
    withStoredInstance()

    // Echoing the input would be the dangerous failure: the caller would carry
    // an unresolvable value on to a write and fail at the foreign key instead.
    await expect(resolveInstanceId('no-such-instance')).resolves.toBeNull()
  })
})

describe('requireInstance', () => {
  it('resolves either identifier to the same row', async () => {
    withStoredInstance()

    const byId = await requireInstance(INTERNAL_ID)
    const byName = await requireInstance(EXTERNAL_ID)

    expect(byId.id).toBe(INTERNAL_ID)
    expect(byName.id).toBe(INTERNAL_ID)
  })

  it('throws InstanceNotFoundError for an unknown identifier', async () => {
    withStoredInstance()

    await expect(requireInstance('no-such-instance')).rejects.toBeInstanceOf(InstanceNotFoundError)
  })

  it('names the identifier the caller actually supplied', async () => {
    withStoredInstance()

    // The message has to quote what was sent, not a normalised form: an
    // operator reading the log is holding the external name.
    await expect(requireInstance('demo-gone')).rejects.toThrow('No instance with id demo-gone.')
  })
})
