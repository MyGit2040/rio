# Disconnect reasons

When a WhatsApp connection closes, it closes with a numeric code. The code says
why, and the gateway uses it to decide whether to try again.

That decision is the whole point of this document. Some disconnects are a blip
and reconnecting fixes them. Others mean the number is unlinked, taken over, or
banned — and reconnecting is both useless and visible to WhatsApp as
suspicious behaviour.

---

## Where these codes come from

The codes are `DisconnectReason` in the Baileys library. They were read
directly from both installed packages:

- `node_modules/baileys-v6/lib/Types/index.d.ts` — `@whiskeysockets/baileys@6.7.23`
- `node_modules/baileys-v7rc/lib/Types/index.d.ts` — `@whiskeysockets/baileys@7.0.0-rc13`

**The two are identical**, value for value. That is why one adapter serves both
packages: the numbers do not shift between the lines.

> The fork (`@itsukichan/baileys@7.3.2`) is not installed by default and was
> **not** checked. It is a fork of the 7.x line so the codes are expected to
> match, but that is an expectation, not a verified fact. Confirm before relying
> on it.

The gateway's own handling is in `classify()` in
`src/baileys/adapter/index.ts`. That is the function the socket manager calls
(`src/baileys/socket-manager.ts`), so it is the runtime behaviour, and the
table below matches it.

> **One inconsistency worth knowing about.** There is a second classifier,
> `classifyDisconnectCode()` in `src/instances/instance-state-machine.ts`. The
> two agree on every code except **411** (`multideviceMismatch`): the adapter
> moves the instance to `ERROR`, the state-machine version moves it to
> `LOGGED_OUT`. Only the adapter's version runs — the other is currently
> referenced solely by its own tests — so the table below is what you will
> observe. Worth reconciling in the code; documented here so the divergence is
> not mistaken for a bug in this table.

---

## The table

| Code | Baileys name | Recovers on its own | State it moves to | What it means, and what to do |
|---|---|---|---|---|
| **401** | `loggedOut` | **No** | `LOGGED_OUT` | The number was unlinked from the phone — usually someone removed the linked device, sometimes WhatsApp did. **Action: link the number again with a new QR code.** The gateway does not retry, because it cannot succeed and would only hammer WhatsApp. |
| **403** | `forbidden` | **No** | `RESTRICTED` | WhatsApp has refused this account. In practice this is a ban or a restriction. **Action: stop using this number.** Do not retry, do not re-link repeatedly — that makes it worse. Read section 11 of the README. |
| **408** | `connectionLost` / `timedOut` | Yes | `DISCONNECTED` | The connection dropped or timed out. Ordinary network trouble. **Action: none — it reconnects.** If it happens all day, look at the server's network and any proxy. Note both names share the code 408; the gateway treats them the same. |
| **411** | `multideviceMismatch` | **No** | `ERROR` | The multi-device state does not line up. The stored session no longer matches what WhatsApp expects. **Action: link the number again.** |
| **428** | `connectionClosed` | Yes | `DISCONNECTED` | The connection closed. Common and usually harmless. **Action: none — it reconnects.** |
| **440** | `connectionReplaced` | **No** | `REPLACED` | Another session took over this number. Usually the same number linked on another server, or someone opened WhatsApp Web. **Action: find the other session and close it, then start this instance again.** The gateway deliberately steps aside rather than fighting the user's own device for the connection — two sessions taking turns is worse than one clean one. |
| **500** | `badSession` | **No** | `ERROR` | The saved session is not valid. Usually corruption, or a session saved by a different Baileys package. **Action: check for a package mismatch first (see below); otherwise link the number again.** |
| **503** | `unavailableService` | Yes | `DISCONNECTED` | WhatsApp's service is temporarily unavailable. Their side, not yours. **Action: none — it retries with a growing delay.** |
| **515** | `restartRequired` | Yes | `DISCONNECTED` | Baileys asked for a restart. Normal, and it happens routinely just after linking a new number. **Action: none — it restarts itself.** |
| *anything else* | — | Yes | `DISCONNECTED` | An unrecognised code. Treated as temporary so one unknown blip cannot permanently kill a working number, and reported verbatim in the logs and diagnostics so the gap is visible instead of hidden. **Action: if it repeats, capture the code from the logs.** |

---

## How the gateway retries

Only the recoverable reasons are retried. When one occurs:

1. The instance moves to `DISCONNECTED`, then `RECONNECT_WAIT`.
2. It waits, and the wait grows after each failure — up to
   `MAX_RECONNECT_DELAY_SECONDS` (300 seconds by default).
3. It tries again, up to `MAX_RECONNECT_ATTEMPTS` (8 by default) within
   `RECONNECT_WINDOW_MINUTES` (30 by default).
4. If the attempts run out inside that window, the retries stop and the
   instance needs attention.

The growing delay is not politeness. Reconnecting hard and fast after a failure
is a pattern WhatsApp can see, and it is a good way to turn a temporary problem
into a permanent one.

---

## Before you re-link on a 500

Code 500 (`badSession`) is worth one check before you go and re-link a number.

If `BAILEYS_PACKAGE` was changed since that number was linked, that is the more
likely cause. A session saved by one Baileys package is not guaranteed to be
readable by another.

The gateway normally catches this before the socket opens and refuses with a
clear message, rather than letting the session corrupt:

```
Session for instance <id> was created with Baileys package 'v6' but 'v7rc' is
selected...
```

If you see that message, set `BAILEYS_PACKAGE` back to the package named in it.
Do **not** re-link. See section 6 of the README.

---

## Quick reference

**These fix themselves — do nothing:**
408, 428, 503, 515

**These need a new QR code:**
401 (logged out), 411 (mismatch), 500 (bad session — after ruling out a package
mismatch)

**These need a decision, not a re-link:**
440 (find and close the other session), 403 (stop using this number)
