import { z } from 'zod'

/**
 * Environment contract.
 *
 * Parsed once at boot and validated hard: a gateway that starts with a missing
 * signing secret or a malformed encryption key would appear healthy while being
 * unable to authenticate a request or decrypt a session. Failing at boot is the
 * loud, cheap failure; failing later is the silent, expensive one.
 */

const bool = z
  .string()
  .transform((v) => v.trim().toLowerCase())
  .pipe(z.enum(['true', 'false', '1', '0', 'yes', 'no']))
  .transform((v) => v === 'true' || v === '1' || v === 'yes')

const int = (fallback: number) =>
  z
    .string()
    .optional()
    .transform((v) => (v === undefined || v.trim() === '' ? fallback : Number(v)))
    .pipe(z.number().int())

const csv = z
  .string()
  .optional()
  .transform((v) =>
    (v ?? '')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean),
  )

export const BAILEYS_PACKAGES = ['v6', 'v7rc', 'fork'] as const
export type BaileysPackageId = (typeof BAILEYS_PACKAGES)[number]

const schema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('production'),
  PORT: int(3090),

  // Identifies this process in the Redis ownership lease. Two replicas sharing
  // a node id would each believe they own the same socket.
  APP_NODE_ID: z.string().min(1, 'APP_NODE_ID is required and must be unique per gateway process'),

  DATABASE_URL: z.string().min(1, 'DATABASE_URL is required'),
  REDIS_URL: z.string().min(1, 'REDIS_URL is required'),

  // 32 bytes, hex-encoded. Anything shorter weakens every encrypted column.
  MASTER_ENCRYPTION_KEY: z
    .string()
    .regex(/^[0-9a-fA-F]{64}$/, 'MASTER_ENCRYPTION_KEY must be 64 hex characters (32 bytes)'),

  LARAVEL_API_KEY: z.string().min(1, 'LARAVEL_API_KEY is required'),
  LARAVEL_SIGNING_SECRET: z.string().min(1, 'LARAVEL_SIGNING_SECRET is required'),

  LARAVEL_WEBHOOK_URL: z.string().url('LARAVEL_WEBHOOK_URL must be a URL'),
  WEBHOOK_SIGNING_SECRET: z.string().min(1, 'WEBHOOK_SIGNING_SECRET is required'),
  WEBHOOK_MAX_ATTEMPTS: int(10),
  WEBHOOK_TIMEOUT_MS: int(15_000),

  BAILEYS_PACKAGE: z.enum(BAILEYS_PACKAGES).default('v6'),
  BAILEYS_ALLOW_PACKAGE_SWITCH: bool.default('false'),

  INSTANCE_STABILIZATION_SECONDS: int(60),
  MAX_RECONNECT_ATTEMPTS: int(8),
  RECONNECT_WINDOW_MINUTES: int(30),
  MAX_RECONNECT_DELAY_SECONDS: int(300),

  INSTANCE_LOCK_TTL_SECONDS: int(60),
  INSTANCE_LOCK_RENEW_SECONDS: int(20),

  MAX_MEDIA_SIZE_MB: int(25),
  TEMP_MEDIA_RETENTION_MINUTES: int(60),
  MEDIA_STORAGE_PATH: z.string().default('/var/lib/eagleto-gateway/media'),
  MEDIA_PUBLIC_BASE_URL: z.string().default(''),

  LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace']).default('info'),
  API_ALLOWED_IPS: csv,
  REQUEST_MAX_SKEW_SECONDS: int(300),
})

export type Env = z.infer<typeof schema>

let cached: Env | null = null

export function loadEnv(source: NodeJS.ProcessEnv = process.env): Env {
  const parsed = schema.safeParse(source)

  if (!parsed.success) {
    // Print every problem at once rather than one per restart.
    const problems = parsed.error.issues
      .map((issue) => `  - ${issue.path.join('.') || '(root)'}: ${issue.message}`)
      .join('\n')

    throw new Error(`Invalid gateway environment:\n${problems}`)
  }

  return parsed.data
}

export function env(): Env {
  if (!cached) {
    cached = loadEnv()
  }

  return cached
}

/** Test seam: lets a suite install a fixture environment. */
export function setEnvForTesting(value: Env | null): void {
  cached = value
}

/**
 * The lock TTL must exceed the renewal interval, otherwise a lease can expire
 * between renewals and a second node can claim a socket that is still open.
 */
export function assertRuntimeInvariants(e: Env): void {
  if (e.INSTANCE_LOCK_RENEW_SECONDS >= e.INSTANCE_LOCK_TTL_SECONDS) {
    throw new Error(
      `INSTANCE_LOCK_RENEW_SECONDS (${e.INSTANCE_LOCK_RENEW_SECONDS}) must be less than ` +
        `INSTANCE_LOCK_TTL_SECONDS (${e.INSTANCE_LOCK_TTL_SECONDS}), or ownership can lapse between renewals.`,
    )
  }

  if (e.MAX_RECONNECT_ATTEMPTS < 1) {
    throw new Error('MAX_RECONNECT_ATTEMPTS must be at least 1.')
  }
}
