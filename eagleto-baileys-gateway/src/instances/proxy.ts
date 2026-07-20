import { HttpsProxyAgent } from 'https-proxy-agent'
import { SocksProxyAgent } from 'socks-proxy-agent'

import { contextLogger } from '../config/logger.js'
import { decryptOptional, encryptOptional } from '../security/encryption.js'

/**
 * Optional per-instance outbound proxy.
 *
 * A proxy here is a routing choice (fixed egress IP, network segregation), not
 * an evasion device. Deliberately absent: automatic rotation mid-session, which
 * would change the connection's apparent origin under a live WhatsApp session
 * and is both unreliable and exactly the kind of behaviour this project does
 * not implement. A proxy is bound to an instance and stays put.
 */

export type ProxyType = 'http' | 'https' | 'socks5'

export interface ProxyConfig {
  type: ProxyType
  host: string
  port: number
  username?: string | null
  password?: string | null
}

interface ProxyBearingInstance {
  id: string
  proxyEnabled: boolean
  proxyType: string | null
  proxyHost: string | null
  proxyPort: number | null
  proxyUsernameEnc: string | null
  proxyPasswordEnc: string | null
}

export function proxyUrl(config: ProxyConfig): string {
  const auth =
    config.username && config.password
      ? `${encodeURIComponent(config.username)}:${encodeURIComponent(config.password)}@`
      : ''

  const scheme = config.type === 'socks5' ? 'socks5' : config.type

  return `${scheme}://${auth}${config.host}:${config.port}`
}

/** Same URL with credentials masked — safe to log or return over the API. */
export function redactedProxyUrl(config: ProxyConfig): string {
  const auth = config.username ? `${config.username}:***@` : ''
  const scheme = config.type === 'socks5' ? 'socks5' : config.type

  return `${scheme}://${auth}${config.host}:${config.port}`
}

export function buildProxyAgent(config: ProxyConfig): unknown {
  const url = proxyUrl(config)

  return config.type === 'socks5' ? new SocksProxyAgent(url) : new HttpsProxyAgent(url)
}

export function decryptProxyConfig(instance: ProxyBearingInstance): ProxyConfig | null {
  if (!instance.proxyEnabled || !instance.proxyHost || !instance.proxyPort || !instance.proxyType) {
    return null
  }

  return {
    type: instance.proxyType as ProxyType,
    host: instance.proxyHost,
    port: instance.proxyPort,
    username: decryptOptional(instance.proxyUsernameEnc),
    password: decryptOptional(instance.proxyPasswordEnc),
  }
}

export function encryptProxyCredentials(input: {
  username?: string | null
  password?: string | null
}): { proxyUsernameEnc: string | null; proxyPasswordEnc: string | null } {
  return {
    proxyUsernameEnc: encryptOptional(input.username),
    proxyPasswordEnc: encryptOptional(input.password),
  }
}

/**
 * Build the agent Baileys should dial through, or undefined for a direct
 * connection.
 */
export async function proxyAgentFor(instance: ProxyBearingInstance): Promise<unknown> {
  const config = decryptProxyConfig(instance)

  if (!config) {
    return undefined
  }

  contextLogger({ instanceId: instance.id }).info(
    { proxy: redactedProxyUrl(config) },
    'Using outbound proxy for this instance',
  )

  return buildProxyAgent(config)
}

export interface ProxyTestResult {
  ok: boolean
  latencyMs?: number
  error?: string
}

/**
 * Verify a proxy before a socket depends on it.
 *
 * Reported separately from WhatsApp authentication failures on purpose: "the
 * proxy is unreachable" and "WhatsApp rejected this account" call for entirely
 * different responses, and conflating them sends operators hunting the wrong
 * problem.
 */
export async function testProxy(config: ProxyConfig, timeoutMs = 10_000): Promise<ProxyTestResult> {
  const startedAt = Date.now()

  try {
    const agent = buildProxyAgent(config)

    const response = await fetch('https://web.whatsapp.com/', {
      method: 'HEAD',
      signal: AbortSignal.timeout(timeoutMs),
      // @ts-expect-error -- undici accepts a dispatcher/agent at runtime; the
      // DOM fetch types do not model it.
      agent,
    })

    return { ok: response.ok || response.status < 500, latencyMs: Date.now() - startedAt }
  } catch (error) {
    return {
      ok: false,
      latencyMs: Date.now() - startedAt,
      error: error instanceof Error ? error.message : String(error),
    }
  }
}
