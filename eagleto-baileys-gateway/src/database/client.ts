import { PrismaClient } from '@prisma/client'

import { env } from '../config/env.js'
import { logger } from '../config/logger.js'

/**
 * Single Prisma client for the process.
 *
 * A module-level singleton rather than per-request construction: each client
 * owns a connection pool, and the auth store writes on nearly every WhatsApp
 * event, so churning clients would exhaust Postgres connections quickly.
 */

let cached: PrismaClient | null = null

function create(): PrismaClient {
  const client = new PrismaClient({
    datasources: { db: { url: env().DATABASE_URL } },
    log:
      env().NODE_ENV === 'development'
        ? [{ emit: 'event', level: 'query' }, { emit: 'event', level: 'warn' }]
        : [{ emit: 'event', level: 'warn' }, { emit: 'event', level: 'error' }],
  })

  // Route Prisma's own diagnostics through the structured logger so database
  // problems land in the same stream as everything else.
  client.$on('warn' as never, (event: unknown) => {
    logger().warn({ prisma: event }, 'Prisma warning')
  })
  client.$on('error' as never, (event: unknown) => {
    logger().error({ prisma: event }, 'Prisma error')
  })

  return client
}

export const prisma: PrismaClient = new Proxy({} as PrismaClient, {
  get(_target, property) {
    if (!cached) {
      cached = create()
    }

    return Reflect.get(cached, property, cached)
  },
})

export async function connectDatabase(): Promise<void> {
  if (!cached) {
    cached = create()
  }

  await cached.$connect()
}

export async function disconnectDatabase(): Promise<void> {
  if (cached) {
    await cached.$disconnect()
    cached = null
  }
}

/** Cheap liveness probe for the health endpoint. */
export async function databaseHealthy(): Promise<boolean> {
  try {
    await prisma.$queryRaw`SELECT 1`

    return true
  } catch {
    return false
  }
}

export function setPrismaForTesting(client: PrismaClient | null): void {
  cached = client
}
