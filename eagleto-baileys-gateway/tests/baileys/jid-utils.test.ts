import { describe, expect, it } from 'vitest'

import {
  isBroadcastJid,
  isGroupJid,
  isLidJid,
  jidsEqual,
  normaliseJid,
  phoneFromJid,
  toJid,
} from '../../src/baileys/jid-utils.js'

const USER = '971501234567@s.whatsapp.net'

describe('toJid', () => {
  it('turns a bare phone number into a user JID', () => {
    expect(toJid('971501234567')).toBe(USER)
  })

  it('strips a leading +, spaces, dashes and parentheses', () => {
    expect(toJid('+971 50 123-4567')).toBe(USER)
    expect(toJid('+971-50-123-4567')).toBe(USER)
    expect(toJid('(971) 50 1234567')).toBe(USER)
    expect(toJid('  971501234567  ')).toBe(USER)
  })

  it('passes through anything that already contains an @', () => {
    expect(toJid(USER)).toBe(USER)
    expect(toJid('120363001122334455@g.us')).toBe('120363001122334455@g.us')
    expect(toJid('status@broadcast')).toBe('status@broadcast')
    // A LID must not be rewritten onto the user domain: its digits are not a
    // phone number and the resulting JID would address a different account.
    expect(toJid('185724920943@lid')).toBe('185724920943@lid')
  })

  it('refuses input with no digits rather than returning a JID addressing nobody', () => {
    expect(() => toJid('not-a-number')).toThrow(/no digits/)
    expect(() => toJid('')).toThrow(/no digits/)
  })
})

describe('jid classification', () => {
  it('detects group JIDs', () => {
    expect(isGroupJid('120363001122334455@g.us')).toBe(true)
    expect(isGroupJid(USER)).toBe(false)
  })

  it('detects broadcast JIDs, including the status feed', () => {
    expect(isBroadcastJid('status@broadcast')).toBe(true)
    expect(isBroadcastJid('123456789@broadcast')).toBe(true)
    expect(isBroadcastJid(USER)).toBe(false)
  })

  it('detects LID JIDs', () => {
    expect(isLidJid('185724920943@lid')).toBe(true)
    expect(isLidJid(USER)).toBe(false)
    expect(isLidJid('120363001122334455@g.us')).toBe(false)
  })
})

describe('normaliseJid', () => {
  it('strips the device suffix so one contact is one identity', () => {
    expect(normaliseJid('971501234567:5@s.whatsapp.net')).toBe(USER)
    expect(normaliseJid('971501234567:12@s.whatsapp.net')).toBe(USER)
  })

  it('leaves a JID without a device suffix unchanged', () => {
    expect(normaliseJid(USER)).toBe(USER)
  })

  it('does not mistake a group JID hyphen for a device suffix', () => {
    expect(normaliseJid('120363001122334455-1600000000@g.us')).toBe(
      '120363001122334455-1600000000@g.us',
    )
  })
})

describe('jidsEqual', () => {
  it('treats a device-suffixed JID as the same person', () => {
    expect(jidsEqual('971501234567:5@s.whatsapp.net', USER)).toBe(true)
    expect(jidsEqual(USER, '971501234567:12@s.whatsapp.net')).toBe(true)
    expect(jidsEqual('971501234567:5@s.whatsapp.net', '971501234567:9@s.whatsapp.net')).toBe(true)
  })

  it('still distinguishes different accounts', () => {
    expect(jidsEqual(USER, '971509999999@s.whatsapp.net')).toBe(false)
    // Same digits, different namespace — not the same identity.
    expect(jidsEqual('971501234567@lid', USER)).toBe(false)
  })
})

describe('phoneFromJid', () => {
  it('returns the digits of a user JID', () => {
    expect(phoneFromJid(USER)).toBe('971501234567')
    expect(phoneFromJid('971501234567:5@s.whatsapp.net')).toBe('971501234567')
    expect(phoneFromJid('971501234567@c.us')).toBe('971501234567')
  })

  it('returns empty for a LID, which carries no phone number', () => {
    expect(phoneFromJid('185724920943@lid')).toBe('')
  })

  it('returns empty for groups, broadcasts and underivable input', () => {
    expect(phoneFromJid('120363001122334455@g.us')).toBe('')
    expect(phoneFromJid('status@broadcast')).toBe('')
    expect(phoneFromJid('971501234567')).toBe('')
    expect(phoneFromJid('')).toBe('')
  })
})
