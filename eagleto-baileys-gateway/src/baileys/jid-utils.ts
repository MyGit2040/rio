/**
 * WhatsApp JID handling.
 *
 * Pure functions, no I/O and no Baileys import, so the rules below can be
 * unit-tested exhaustively — identity comparison is the kind of thing that is
 * either exactly right or silently wrong for months.
 */

/** Individual accounts. Groups, broadcasts and LIDs each have their own. */
const USER_DOMAIN = 's.whatsapp.net'

/**
 * Domains whose user part is genuinely a phone number.
 *
 * `c.us` is the legacy individual domain and still appears in older payloads.
 * Everything else — groups, broadcasts, and above all `@lid` — must never be
 * mined for a phone number, which is why this is an allowlist rather than a
 * "not a group" check.
 */
const PHONE_BEARING_DOMAINS: ReadonlySet<string> = new Set([USER_DOMAIN, 'c.us'])

/**
 * Normalise a phone number or existing JID to `<digits>@s.whatsapp.net`.
 *
 * Anything already containing `@` is passed through untouched: it may be a
 * group, a broadcast or a LID, and rewriting those to the user domain would
 * silently redirect a message to a different (or non-existent) chat.
 *
 * Throws when the input yields no digits. Returning `"@s.whatsapp.net"` would
 * be a syntactically plausible JID that addresses nobody — the failure would
 * surface as a confusing send error far from the bad input.
 */
export function toJid(input: string): string {
  const trimmed = input.trim()

  if (trimmed.includes('@')) {
    return trimmed
  }

  // Strips `+`, spaces, dashes, parentheses and dots in one pass — every
  // separator humans put in a phone number.
  const digits = trimmed.replace(/\D/g, '')

  if (digits === '') {
    throw new Error(`Cannot build a JID from "${input}": it contains no digits.`)
  }

  return `${digits}@${USER_DOMAIN}`
}

export function isGroupJid(jid: string): boolean {
  return jid.trim().toLowerCase().endsWith('@g.us')
}

/** True for both the status feed (`status@broadcast`) and broadcast lists. */
export function isBroadcastJid(jid: string): boolean {
  return jid.trim().toLowerCase().endsWith('@broadcast')
}

/**
 * True for WhatsApp's privacy identifier (LID).
 *
 * A LID deliberately carries no phone number. Callers must not attempt to
 * derive one from it — `phoneFromJid` returns an empty string for exactly this
 * reason, and treating the user part as a number would produce a real-looking
 * but entirely fictitious phone number.
 */
export function isLidJid(jid: string): boolean {
  return jid.trim().toLowerCase().endsWith('@lid')
}

/**
 * Strip a device/agent suffix so the same contact compares equal in every form.
 *
 * Baileys reports one contact as both `1234@s.whatsapp.net` and
 * `1234:5@s.whatsapp.net` — the `:5` identifies which of the account's linked
 * devices sent the message. A naive string comparison therefore treats one
 * person as several, which breaks de-duplication, "is this from me?" checks and
 * per-contact rate limiting. Lowercasing is safe because JIDs are digits plus a
 * fixed lowercase domain, so no information is lost.
 */
export function normaliseJid(jid: string): string {
  const value = jid.trim().toLowerCase()
  const at = value.indexOf('@')

  if (at === -1) {
    return value
  }

  const user = value.slice(0, at)
  const domain = value.slice(at + 1)
  const colon = user.indexOf(':')

  return `${colon === -1 ? user : user.slice(0, colon)}@${domain}`
}

export function jidsEqual(a: string, b: string): boolean {
  return normaliseJid(a) === normaliseJid(b)
}

/**
 * The phone number carried by a JID, or an empty string when there is none.
 *
 * Empty is returned for groups, broadcasts, LIDs and any non-numeric user part
 * — all cases where a number cannot be derived. Callers get one unambiguous
 * "no number here" signal instead of a plausible-looking wrong answer.
 */
export function phoneFromJid(jid: string): string {
  const normalised = normaliseJid(jid)
  const at = normalised.indexOf('@')

  if (at === -1) {
    return ''
  }

  const user = normalised.slice(0, at)

  if (!PHONE_BEARING_DOMAINS.has(normalised.slice(at + 1))) {
    return ''
  }

  return /^\d+$/.test(user) ? user : ''
}
