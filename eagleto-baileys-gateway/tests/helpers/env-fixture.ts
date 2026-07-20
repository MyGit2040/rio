import { loadEnv, type Env } from '../../src/config/env.js'

/**
 * A complete, valid environment for tests.
 *
 * Built through loadEnv rather than by hand-writing an Env object, so the
 * fixture is validated by the same schema the gateway boots with — if a new
 * required variable is added, these tests fail loudly instead of drifting.
 */

export const TEST_API_KEY = 'test-api-key-3f9c1a'
export const TEST_SIGNING_SECRET = 'test-signing-secret-c7b21e4d'

/** 64 hex characters = the 32 bytes MASTER_ENCRYPTION_KEY requires. */
export const TEST_MASTER_KEY = 'a'.repeat(64)

const BASE: NodeJS.ProcessEnv = {
  NODE_ENV: 'test',
  APP_NODE_ID: 'test-node',
  DATABASE_URL: 'postgresql://localhost:5432/eagleto_test',
  REDIS_URL: 'redis://localhost:6379',
  MASTER_ENCRYPTION_KEY: TEST_MASTER_KEY,
  LARAVEL_API_KEY: TEST_API_KEY,
  LARAVEL_SIGNING_SECRET: TEST_SIGNING_SECRET,
  LARAVEL_WEBHOOK_URL: 'https://laravel.test/api/gateway/webhook',
  WEBHOOK_SIGNING_SECRET: 'test-webhook-secret',
  LOG_LEVEL: 'warn',
  REQUEST_MAX_SKEW_SECONDS: '300',
  API_ALLOWED_IPS: '',
}

export function testEnv(overrides: NodeJS.ProcessEnv = {}): Env {
  return loadEnv({ ...BASE, ...overrides })
}
