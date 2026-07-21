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

  // --- Human-like send behaviour (anti-ban) -------------------------------
  // A "typing…" indicator is shown before each text send: a think pause of
  // [THINK_MIN, THINK_MAX] ms, then a compose window of ~MS_PER_CHAR per
  // character, clamped to [MIN_TYPING, MAX_TYPING] ms. Disable to send with no
  // presence at all.
  PRESENCE_SIMULATION_ENABLED: bool.default('true'),
  PRESENCE_MS_PER_CHAR: int(30),
  PRESENCE_MIN_TYPING_MS: int(900),
  PRESENCE_MAX_TYPING_MS: int(6000),
  PRESENCE_THINK_MIN_MS: int(500),
  PRESENCE_THINK_MAX_MS: int(1500),

  // Delayed read receipts: an inbound message is marked read after a randomised
  // 2–7 s (Gaussian) pause instead of instantly, so blue ticks don't appear at
  // machine speed. OFF by default — enabling it means the account sends read
  // receipts it currently sends none of.
  READ_RECEIPT_SIMULATION_ENABLED: bool.default('false'),
  READ_RECEIPT_MIN_MS: int(2000),
  READ_RECEIPT_MAX_MS: int(7000),

  // Group-action brake: minimum gap between actions targeting a group chat on
  // one number, so group sends can never fire back-to-back.
  GROUP_ACTION_COOLDOWN_MS: int(15_000),

  // Background entropy loop: every ENTROPY_MIN..MAX hours a live number performs
  // one harmless "I'm a real phone" action (a presence blip / own-profile
  // fetch) with NO outbound message. OFF by default.
  ENTROPY_ENABLED: bool.default('false'),
  ENTROPY_MIN_HOURS: int(2),
  ENTROPY_MAX_HOURS: int(6),

  // Post-reconnect send ramp: for RECONNECT_RAMP_SECONDS after a number becomes
  // sendable again, each send is held by an extra pause that starts near
  // RECONNECT_RAMP_MAX_EXTRA_MS and decays to zero — so a recovered number eases
  // back to full rate instead of blasting a queued batch the instant it returns.
  RECONNECT_RAMP_SECONDS: int(60),
  RECONNECT_RAMP_MAX_EXTRA_MS: int(4000),

  // Session-health risk score (0 = healthy … 100 = critical). A gateway
  // health_warning webhook fires when a number crosses WARN, and again at
  // CRITICAL, so Laravel can pause its queue before WhatsApp acts. Crossing
  // CRITICAL also halts this number's sends for CRITICAL_COOLDOWN_MINUTES
  // (circuit breaker) rather than fighting a degrading session.
  SESSION_HEALTH_WARN_SCORE: int(50),
  SESSION_HEALTH_CRITICAL_SCORE: int(80),
  SESSION_HEALTH_CRITICAL_COOLDOWN_MINUTES: int(60),

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
