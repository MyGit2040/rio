import { prisma } from '../database/client.js'

/**
 * Replay protection.
 *
 * A valid signature stays valid for the whole skew window, so without a
 * single-use nonce anyone who observes a request can resend it verbatim until
 * it expires — resending a "send message" call as many times as they like.
 *
 * The store is an interface so the API layer can be tested without a database,
 * and so a Redis-backed implementation can replace the Prisma one later
 * without touching the middleware.
 */
export interface NonceStore {
  /**
   * Records a nonce as used.
   *
   * @returns true if this is the first time the nonce has been presented,
   *          false if it was already spent (a replay).
   * @throws  if the backing store is unavailable — the caller must fail the
   *          request closed rather than treat an outage as "not a replay".
   */
  consume(nonce: string): Promise<boolean>
}

/** Prisma's unique-constraint violation. */
const UNIQUE_VIOLATION = 'P2002'

/**
 * Duck-typed rather than instanceof PrismaClientKnownRequestError: the error
 * class comes from the generated client, and a version mismatch between the
 * generated package and the runtime one would silently make an instanceof
 * check false — turning every replay into a 500 instead of a 401.
 */
function isUniqueViolation(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'code' in error &&
    (error as { code?: unknown }).code === UNIQUE_VIOLATION
  )
}

export class PrismaNonceStore implements NonceStore {
  async consume(nonce: string): Promise<boolean> {
    try {
      // The insert IS the check. A read-then-write would leave a gap in which
      // two concurrent replays of the same nonce both see "unused" and both
      // succeed; the primary key makes the database settle it.
      await prisma.requestNonce.create({ data: { nonce } })

      return true
    } catch (error) {
      if (isUniqueViolation(error)) {
        return false
      }

      // Anything else (connection lost, table missing) is not a replay and
      // must not be reported as one — rethrow so the caller fails closed.
      throw error
    }
  }
}

/**
 * Deletes nonces older than the given age.
 *
 * Without this the table grows by one row per authenticated request forever.
 * The age must be at least the accepted clock skew: a nonce pruned while its
 * signature is still inside the window becomes replayable again.
 */
export async function pruneNonces(olderThanSeconds: number): Promise<number> {
  if (!Number.isFinite(olderThanSeconds) || olderThanSeconds <= 0) {
    // A zero or negative age would delete the entire live replay window, which
    // is a silent security regression rather than an obvious bug. Refuse it.
    throw new Error(
      `pruneNonces requires a positive age in seconds, received ${String(olderThanSeconds)}.`,
    )
  }

  const cutoff = new Date(Date.now() - olderThanSeconds * 1000)
  const result = await prisma.requestNonce.deleteMany({ where: { seenAt: { lt: cutoff } } })

  return result.count
}
