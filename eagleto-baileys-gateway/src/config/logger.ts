import pino, { type Logger } from 'pino'

import { env } from './env.js'

/**
 * Structured logging with hard redaction.
 *
 * WhatsApp auth material, proxy passwords and API secrets must never reach a
 * log sink — not at debug level, not in an error dump. Redaction is configured
 * centrally here so no call site has to remember, and the message body is
 * excluded in production because logs are not the place for customer content.
 */

const REDACTED_PATHS = [
  'creds',
  '*.creds',
  'keys',
  '*.keys',
  'authState',
  '*.authState',
  'password',
  '*.password',
  'proxyPassword',
  '*.proxyPassword',
  'proxyUsername',
  '*.proxyUsername',
  'apiKey',
  '*.apiKey',
  'secret',
  '*.secret',
  'signature',
  '*.signature',
  'authorization',
  '*.authorization',
  'req.headers.authorization',
  'req.headers["x-eagleto-key"]',
  'req.headers["x-eagleto-signature"]',
  'credentialsEnc',
  '*.credentialsEnc',
  'valueEnc',
  '*.valueEnc',
  'webhookSecretEnc',
  '*.webhookSecretEnc',
  'proxyPasswordEnc',
  '*.proxyPasswordEnc',
]

let cached: Logger | null = null

export function logger(): Logger {
  if (cached) {
    return cached
  }

  const e = env()
  const isProduction = e.NODE_ENV === 'production'

  cached = pino({
    level: e.LOG_LEVEL,
    // The QR payload is a live credential until scanned, and message bodies are
    // customer content. Both are dropped outside development.
    redact: {
      paths: isProduction ? [...REDACTED_PATHS, 'qr', '*.qr', 'body', '*.body', 'text', '*.text'] : REDACTED_PATHS,
      censor: '[redacted]',
    },
    base: { nodeId: e.APP_NODE_ID },
    timestamp: pino.stdTimeFunctions.isoTime,
    formatters: {
      level: (label) => ({ level: label }),
    },
    ...(isProduction
      ? {}
      : {
          transport: {
            target: 'pino-pretty',
            options: { colorize: true, translateTime: 'HH:MM:ss.l', ignore: 'pid,hostname' },
          },
        }),
  })

  return cached
}

/** Child logger carrying the identifiers every gateway log line should have. */
export function contextLogger(context: {
  requestId?: string
  instanceId?: string
  tenantReference?: string
  gatewayMessageId?: string
  clientMessageId?: string
  eventId?: string
}): Logger {
  return logger().child(context)
}

export function setLoggerForTesting(value: Logger | null): void {
  cached = value
}
