import { createRequire } from 'node:module'

import { env, type BaileysPackageId } from '../config/env.js'
import { contextLogger } from '../config/logger.js'
import { prisma } from '../database/client.js'
import { decrypt, encrypt } from '../security/encryption.js'

import { BAILEYS_PACKAGE_SPECS, type AuthStateBridge } from './adapter/types.js'

/**
 * PostgreSQL-backed Baileys authentication state.
 *
 * Baileys' bundled `useMultiFileAuthState` is explicitly a demonstration store
 * and is not safe for production: it writes loose files with no transaction
 * boundary, so a crash mid-write can leave a half-updated key set and silently
 * corrupt the session. This implementation persists credentials and Signal keys
 * as encrypted rows, batches reads, and makes every write atomic.
 *
 * Correctness note: Baileys mutates auth state constantly during normal
 * send/receive, not just at login. Every `creds.update` MUST be persisted or
 * the session degrades and eventually forces a re-scan.
 */

const require = createRequire(import.meta.url)

export class PackageMismatchError extends Error {
  constructor(
    readonly instanceId: string,
    readonly storedPackage: string,
    readonly selectedPackage: string,
  ) {
    super(
      `Session for instance ${instanceId} was created with Baileys package '${storedPackage}' but ` +
        `'${selectedPackage}' is selected. Auth state is not guaranteed portable between Baileys ` +
        `lines, and opening this session with a different implementation risks corrupting it — ` +
        `which would force a QR re-scan. Either set BAILEYS_PACKAGE=${storedPackage}, or set ` +
        `BAILEYS_ALLOW_PACKAGE_SWITCH=true and expect to re-link this number.`,
    )
    this.name = 'PackageMismatchError'
  }
}

/**
 * Baileys stores Buffers inside its creds/keys objects. BufferJSON is its own
 * replacer/reviver pair for round-tripping those through JSON — using plain
 * JSON.stringify would flatten Buffers into `{type:'Buffer',data:[...]}` and
 * the restored session would fail Signal decryption in a way that looks like a
 * random disconnect hours later.
 */
function bufferJson(moduleName: string): { replacer: unknown; reviver: unknown } {
  const mod = require(moduleName) as { BufferJSON: { replacer: unknown; reviver: unknown } }

  return mod.BufferJSON
}

function serialise(value: unknown, moduleName: string): string {
  const { replacer } = bufferJson(moduleName)

  return JSON.stringify(value, replacer as never)
}

function deserialise<T>(payload: string, moduleName: string): T {
  const { reviver } = bufferJson(moduleName)

  return JSON.parse(payload, reviver as never) as T
}

export interface AuthStoreHandle {
  state: AuthStateBridge
  /** Persist the current credentials. Call on every `creds.update`. */
  saveCreds(): Promise<void>
  /** Remove all auth material for this instance (logout / re-link). */
  clear(): Promise<void>
}

/**
 * Open (or create) the auth state for an instance.
 *
 * @throws PackageMismatchError when the stored session was written by a
 *         different Baileys implementation and switching is not permitted.
 */
export async function openAuthStore(
  instanceId: string,
  packageId: BaileysPackageId,
  packageVersion: string,
): Promise<AuthStoreHandle> {
  const log = contextLogger({ instanceId })
  const moduleName = BAILEYS_PACKAGE_SPECS[packageId].moduleName
  const { initAuthCreds } = require(moduleName) as { initAuthCreds: () => unknown }

  const existing = await prisma.authCredential.findUnique({ where: { instanceId } })

  if (existing && existing.baileysPackage !== packageId && !env().BAILEYS_ALLOW_PACKAGE_SWITCH) {
    throw new PackageMismatchError(instanceId, existing.baileysPackage, packageId)
  }

  if (existing && existing.baileysPackage !== packageId) {
    log.warn(
      { storedPackage: existing.baileysPackage, selectedPackage: packageId },
      'Opening session with a different Baileys package because BAILEYS_ALLOW_PACKAGE_SWITCH is enabled. ' +
        'A re-link may be required.',
    )
  }

  const creds = existing ? deserialise<Record<string, unknown>>(decrypt(existing.credentialsEnc), moduleName) : initAuthCreds()

  const state: AuthStateBridge = {
    creds,

    keys: {
      async get(type: string, ids: string[]): Promise<Record<string, unknown>> {
        if (ids.length === 0) {
          return {}
        }

        // One query for the whole batch: Baileys asks for many keys at once
        // during decryption, and per-id round trips would dominate send latency.
        const rows = await prisma.authKey.findMany({
          where: { instanceId, category: type, keyId: { in: ids } },
          select: { keyId: true, valueEnc: true },
        })

        const result: Record<string, unknown> = {}

        for (const row of rows) {
          try {
            const value = deserialise<unknown>(decrypt(row.valueEnc), moduleName)

            // Baileys expects app-state-sync-keys as protobuf objects rather
            // than plain ones; the module exposes the type for rehydration.
            if (type === 'app-state-sync-key') {
              const { proto } = require(moduleName) as {
                proto: { Message: { AppStateSyncKeyData: { fromObject(v: unknown): unknown } } }
              }
              result[row.keyId] = proto.Message.AppStateSyncKeyData.fromObject(value as object)
            } else {
              result[row.keyId] = value
            }
          } catch (error) {
            // A single unreadable key must not take down the whole batch — the
            // session can usually recover by re-requesting it. Losing the batch
            // would drop the connection instead.
            log.error(
              { category: type, keyId: row.keyId, err: (error as Error).message },
              'Could not decrypt a stored Signal key; skipping it',
            )
          }
        }

        return result
      },

      async set(data: Record<string, Record<string, unknown> | null | undefined>): Promise<void> {
        const upserts: Array<{ category: string; keyId: string; valueEnc: string }> = []
        const deletions: Array<{ category: string; keyId: string }> = []

        for (const [category, entries] of Object.entries(data)) {
          if (!entries) {
            continue
          }

          for (const [keyId, value] of Object.entries(entries)) {
            if (value === null || value === undefined) {
              deletions.push({ category, keyId })
            } else {
              upserts.push({ category, keyId, valueEnc: encrypt(serialise(value, moduleName)) })
            }
          }
        }

        if (upserts.length === 0 && deletions.length === 0) {
          return
        }

        // One transaction for the whole mutation: Signal state is only coherent
        // as a set, so a partial write is worse than no write.
        await prisma.$transaction(async (tx) => {
          for (const row of upserts) {
            await tx.authKey.upsert({
              where: {
                instanceId_category_keyId: {
                  instanceId,
                  category: row.category,
                  keyId: row.keyId,
                },
              },
              create: { instanceId, category: row.category, keyId: row.keyId, valueEnc: row.valueEnc },
              update: { valueEnc: row.valueEnc },
            })
          }

          for (const row of deletions) {
            await tx.authKey.deleteMany({
              where: { instanceId, category: row.category, keyId: row.keyId },
            })
          }
        })
      },
    },
  }

  const saveCreds = async (): Promise<void> => {
    const credentialsEnc = encrypt(serialise(state.creds, moduleName))

    await prisma.authCredential.upsert({
      where: { instanceId },
      create: {
        instanceId,
        credentialsEnc,
        baileysPackage: packageId,
        baileysVersion: packageVersion,
        lastWriteAt: new Date(),
      },
      update: {
        credentialsEnc,
        baileysPackage: packageId,
        baileysVersion: packageVersion,
        lastWriteAt: new Date(),
      },
    })
  }

  const clear = async (): Promise<void> => {
    await prisma.$transaction([
      prisma.authKey.deleteMany({ where: { instanceId } }),
      prisma.authCredential.deleteMany({ where: { instanceId } }),
    ])
  }

  // A brand-new session is persisted immediately so a crash between socket
  // creation and the first creds.update cannot orphan the instance.
  if (!existing) {
    await saveCreds()
  }

  return { state, saveCreds, clear }
}

/**
 * When the auth store last succeeded in writing. Surfaced on the health
 * endpoint: a stalled write clock is the earliest warning that sessions are
 * about to start failing.
 */
export async function lastCredentialWriteAt(instanceId: string): Promise<Date | null> {
  const row = await prisma.authCredential.findUnique({
    where: { instanceId },
    select: { lastWriteAt: true },
  })

  return row?.lastWriteAt ?? null
}
