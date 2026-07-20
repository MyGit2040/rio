# Recovery playbook

Step-by-step procedures for the things that go wrong. Work through a procedure
in order and do not skip steps.

Throughout, `/opt/eagleto-baileys-gateway` stands for wherever the project
lives on your server. The directory is shown above every command block.

**Two rules that apply to all of it:**

1. Never delete a volume (`docker compose down -v`) unless you intend to erase
   every WhatsApp session on this gateway.
2. Never change `MASTER_ENCRYPTION_KEY` to make an error go away. It does not
   fix anything and it destroys every stored session.

---

## Contents

1. [The gateway will not start](#1-the-gateway-will-not-start)
2. [All numbers disconnected at once](#2-all-numbers-disconnected-at-once)
3. [One number stuck in RECONNECT_WAIT](#3-one-number-stuck-in-reconnect_wait)
4. [Webhook backlog and dead letters](#4-webhook-backlog-and-dead-letters)
5. [Restoring PostgreSQL](#5-restoring-postgresql)
6. [The encryption key is lost](#6-the-encryption-key-is-lost)
7. [Suspected duplicate sends](#7-suspected-duplicate-sends)

---

## 1. The gateway will not start

The container exits immediately, or restarts in a loop.

### Step 1 — Read the reason

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 100 baileys-gateway
```

The startup check validates the whole configuration before anything else runs,
and prints **every** problem at once rather than one per restart. Read the
whole list.

### Step 2 — Match the message

| Message contains | Cause | Fix |
|---|---|---|
| `MASTER_ENCRYPTION_KEY must be 64 hex characters` | The key is missing, short, or has stray characters. | Restore the correct key. If this is a first install, generate one: `openssl rand -hex 32`. If this is **not** a first install, do not generate a new one — go to section 6. |
| `APP_NODE_ID is required` | Not set. | Set it in `.env`, for example `gateway-1`. |
| `DATABASE_URL is required` | Not set. | Set it in `.env`. |
| `LARAVEL_API_KEY is required` | Not set. | Set it, and set the same value in Laravel. |
| `LARAVEL_WEBHOOK_URL must be a URL` | Missing or malformed. Must be a full URL with a scheme. | Set it, for example `https://rio.eagleto.com/webhooks/baileys`. |
| `INSTANCE_LOCK_RENEW_SECONDS ... must be less than INSTANCE_LOCK_TTL_SECONDS` | The lock renews less often than it expires. | Make renew smaller than TTL. The defaults are 20 and 60. |
| `MAX_RECONNECT_ATTEMPTS must be at least 1` | Set to zero. | Set it to 8, or another positive number. |
| `Could not load Baileys package 'fork'` | `BAILEYS_PACKAGE=fork` but the fork is not installed. | Set `BAILEYS_PACKAGE=v6`, or install the fork deliberately — README section 6. |
| Cannot reach the database | Postgres is not up yet, or the password is wrong. | Continue to step 3. |

### Step 3 — Check the dependencies

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose ps
```

Postgres and Redis must both show `healthy`. If Postgres is not:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 50 postgres
```

Check free disk, which is a common cause:

Directory: any

```
df -h
```

### Step 4 — Confirm the database password matches

The password appears twice and both must agree: `POSTGRES_PASSWORD`, and the
password inside `DATABASE_URL`.

Directory: `/opt/eagleto-baileys-gateway`

```
grep -E 'POSTGRES_PASSWORD|DATABASE_URL' .env
```

If you changed `POSTGRES_PASSWORD` after the first start, the database still
has the old one — the value only initialises a new database. Either put the old
password back in `.env`, or change it inside Postgres.

### Step 5 — Start again

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose up -d
```

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs -f baileys-gateway
```

---

## 2. All numbers disconnected at once

Every number dropped at the same moment. That points at the gateway or its
network, not at any individual number.

### Step 1 — Is the process running

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose ps
```

If the gateway is not running, go to section 1.

### Step 2 — Can it reach its dependencies

Directory: `/opt/eagleto-baileys-gateway`

```
curl -s http://127.0.0.1:3090/health/ready
```

A `critical` level names the failing dependency.

### Step 3 — Did it restart

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 300 baileys-gateway | grep -iE 'shutdown|starting|SIGTERM|out of memory'
```

A restart drops every socket. Numbers reconnect from stored sessions on their
own — that is expected and needs no action beyond waiting.

If the container was killed for memory, raise the limit in
`docker-compose.yml` under `deploy.resources.limits`.

### Step 4 — Check outbound network

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec baileys-gateway node -e "fetch('https://web.whatsapp.com',{method:'HEAD'}).then(r=>console.log('reachable',r.status)).catch(e=>console.log('FAILED',e.message))"
```

If that fails, the container has no route out. Check the host's connectivity,
its firewall, and any proxy configuration.

### Step 5 — Wait, then check states

Reconnection is not instant. Attempts back off up to
`MAX_RECONNECT_DELAY_SECONDS` (300 by default), so give it five to ten minutes.

Then check each number's state in Eagleto. Anything in `DISCONNECTED` or
`RECONNECT_WAIT` is still working on it. Anything in `LOGGED_OUT`, `REPLACED`
or `RESTRICTED` has a different problem — see `DISCONNECT_REASONS.md`.

### Step 6 — Only if nothing recovers

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose restart baileys-gateway
```

Restarting is not a first resort. It drops every socket that was still healthy.

---

## 3. One number stuck in RECONNECT_WAIT

One number is not recovering while the others are fine. The problem is that
number, its session, or its proxy.

### Step 1 — Find out why it disconnected

Look at the instance's last error in Eagleto, or:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 1000 baileys-gateway | grep '<the instance id>'
```

Match the code against `DISCONNECT_REASONS.md`.

### Step 2 — Check it is genuinely stuck

`RECONNECT_WAIT` is a normal waiting state. The delay grows after each failure
and reaches 300 seconds by default. Watch for ten minutes before deciding it is
stuck.

If the attempt count has stopped rising, it has exhausted
`MAX_RECONNECT_ATTEMPTS` and stopped by design.

### Step 3 — Rule out a package mismatch

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 1000 baileys-gateway | grep -i 'PackageMismatch'
```

If that matches, `BAILEYS_PACKAGE` was changed after this number was linked.
Set it back to the package named in the message. Do not re-link.

### Step 4 — Test the proxy, if one is set

If the instance uses a proxy, test it from Eagleto. A dead proxy looks exactly
like a dead network for that one number, while every direct number is fine.

### Step 5 — Restart the instance

From Eagleto, restart that instance. This closes and reopens the socket using
the same stored session. **It does not log out and does not need a QR.**

### Step 6 — If it still will not connect

The session is probably no longer usable. Log the instance out and link it
again with a new QR code.

Do this last. It is the only step here that requires someone with the phone.

---

## 4. Webhook backlog and dead letters

Events are saved to the database before any delivery is attempted. A Laravel
outage therefore delays events — it does not lose them. A backlog is a symptom,
not a loss.

### Understand the statuses

| Status | Meaning |
|---|---|
| `PENDING` | Saved, waiting for a worker. Normal. |
| `DELIVERING` | Being delivered right now. Normal. |
| `DELIVERED` | Laravel accepted it. Done. |
| `RETRY_WAIT` | Failed, waiting to retry. Self-correcting. |
| `DEAD_LETTER` | Failed `WEBHOOK_MAX_ATTEMPTS` times (10 by default). **Retries have stopped. This needs you.** |

### Step 1 — See the scale of it

If you have a developer, ask them to list the queue via `GET /v1/webhooks`
(the call must be signed). Otherwise, read it from the database:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres psql -U eagleto -d baileys_gateway -c "SELECT status, count(*) FROM webhook_events GROUP BY status ORDER BY status;"
```

### Step 2 — Find out why they failed

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres psql -U eagleto -d baileys_gateway -c "SELECT id, event_type, attempts, last_status_code, left(last_error, 120) AS error FROM webhook_events WHERE status = 'DEAD_LETTER' ORDER BY updated_at DESC LIMIT 20;"
```

| `last_status_code` / error | Cause | Fix |
|---|---|---|
| 401 or 403 | Laravel rejected the signature. | `WEBHOOK_SIGNING_SECRET` differs between the two systems. Make them match. |
| 404 | Wrong URL. | Fix `LARAVEL_WEBHOOK_URL`. |
| 419 | Laravel's CSRF protection. | The webhook route must be excluded from CSRF. A Laravel change. |
| 422 | Laravel rejected the payload. | A Laravel-side validation problem. Share the event payload with the developer. |
| 500 | Laravel threw an error. | Look in Laravel's own logs. |
| `timed out` | Laravel took longer than `WEBHOOK_TIMEOUT_MS` (15s). | Make the Laravel endpoint faster — it should queue the work, not do it inline. |
| `ECONNREFUSED` / DNS errors | Laravel unreachable from this server. | Network or DNS. |
| `Could not decrypt stored data` | A per-instance webhook secret cannot be read under the current encryption key. | Section 6. |

### Step 3 — Fix the cause first

Replaying before the cause is fixed just dead-letters everything again, more
slowly.

### Step 4 — Replay

Replaying puts an event back in the queue and resets its attempt count to zero.
That reset is deliberate: a dead letter has already spent its budget, and
without the reset the first failure would immediately dead-letter it again.

For a single event, ask a developer to call
`POST /v1/webhooks/{eventId}/replay` (signed).

To replay all of them, put the rows back yourself:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres psql -U eagleto -d baileys_gateway -c "UPDATE webhook_events SET status = 'PENDING', attempts = 0, next_attempt_at = now(), delivered_at = NULL WHERE status = 'DEAD_LETTER';"
```

The worker picks them up within a second or two. Watch:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs -f baileys-gateway | grep -i webhook
```

### Step 5 — Confirm the queue drained

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres psql -U eagleto -d baileys_gateway -c "SELECT status, count(*) FROM webhook_events GROUP BY status ORDER BY status;"
```

### If rows are stuck in DELIVERING

That means a worker was interrupted mid-delivery. The gateway sweeps these back
into the queue on its own after about two minutes. Wait before intervening.

---

## 5. Restoring PostgreSQL

> This replaces the current database entirely.

### Before you begin

You need two things, and both must match:

1. The dump file.
2. The `MASTER_ENCRYPTION_KEY` that was in use **when the dump was taken**.

If the key does not match, the restore appears to succeed and every WhatsApp
session in it is unreadable.

### Step 1 — Stop the gateway, keep the database up

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose stop baileys-gateway
```

### Step 2 — Confirm the key

Directory: `/opt/eagleto-baileys-gateway`

```
grep MASTER_ENCRYPTION_KEY .env
```

Compare it against the key recorded with the backup. If they differ, put the
backup's key in `.env` before continuing.

### Step 3 — Keep what is there now

Even a broken database is worth keeping until the restore is proven.

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres pg_dump -U eagleto -d baileys_gateway -Fc > /var/backups/eagleto-gateway-before-restore-$(date +%F-%H%M).dump
```

### Step 4 — Restore

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres pg_restore -U eagleto -d baileys_gateway --clean --if-exists < /var/backups/eagleto-gateway-2026-07-20-0215.dump
```

Use your own filename.

### Step 5 — Apply any pending migrations

The dump may predate a schema change.

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose start baileys-gateway
```

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose run --rm migrate
```

### Step 6 — Check every number

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs -f baileys-gateway
```

Watch for `Could not decrypt stored data` — that means the key does not match
the dump. Stop and go back to step 2.

Then check each number's state in Eagleto. A restored session can be older than
the one WhatsApp last saw, so some numbers may need re-linking. This is normal
after a restore and is not a sign the restore failed.

---

## 6. The encryption key is lost

> **Read this before doing anything.** Once you re-link the numbers, the old
> data is unrecoverable even if the key turns up afterwards.

### Step 1 — Stop and search

Do not restart the gateway. Do not change `.env`. Do not re-link anything yet.

Look for the key in:

- your password manager
- your deployment or configuration management system
- the `.env` file on any staging or backup server
- an older backup of `/opt/eagleto-baileys-gateway/.env`
- the server's own backups or snapshots
- a container that is stopped but not deleted:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec baileys-gateway printenv MASTER_ENCRYPTION_KEY
```

If the container still runs with the old key in memory, that command prints it.
**Copy it somewhere safe immediately.**

### Step 2 — If you find it

Put it back in `.env`, restart, and confirm the decryption errors stop.

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose up -d
```

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 100 baileys-gateway | grep -i decrypt
```

No output means the key is correct. Then back it up properly — README
section 4.

### Step 3 — If it is genuinely gone

Every WhatsApp number must be linked again. There is no way around it: the
sessions are encrypted with that key and nothing else can read them.

1. Tell whoever owns those numbers. Someone needs each phone to hand.
2. Generate a new key:

   Directory: `/opt/eagleto-baileys-gateway`

   ```
   openssl rand -hex 32
   ```

3. Put it in `.env` as `MASTER_ENCRYPTION_KEY`.
4. **Back it up before continuing.** This is the moment the last one was lost.
5. Clear the unreadable session material. This deletes the stored WhatsApp
   sessions and nothing else — messages, webhook events and instance records
   are kept:

   Directory: `/opt/eagleto-baileys-gateway`

   ```
   docker compose exec -T postgres psql -U eagleto -d baileys_gateway -c "DELETE FROM auth_keys; DELETE FROM auth_credentials;"
   ```

6. Restart:

   Directory: `/opt/eagleto-baileys-gateway`

   ```
   docker compose restart baileys-gateway
   ```

7. Link every number again from Eagleto with a fresh QR code.

> Per-instance proxy passwords and webhook secrets were encrypted with the old
> key too. They will also need re-entering. If an instance had a proxy or its
> own webhook secret, set it again.

---

## 7. Suspected duplicate sends

Someone reports receiving the same message twice.

### Step 1 — Understand what should prevent it

Every send from Laravel carries an idempotency key. The gateway stores it and a
hash of the payload. A second request with the same key does not send a second
message — it returns the original result. That is what makes a Laravel timeout
safe to retry.

So a genuine duplicate at the WhatsApp level means one of:

- Laravel sent two requests with **different** idempotency keys.
- The same content was sent twice deliberately, by two campaigns.
- WhatsApp itself delivered twice — rare, and not visible from here.

### Step 2 — Check what the gateway recorded

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres psql -U eagleto -d baileys_gateway -c "SELECT id, idempotency_key, client_message_id, status, whatsapp_message_id, accepted_at FROM gateway_messages WHERE recipient LIKE '%<the last 6 digits>%' ORDER BY accepted_at DESC LIMIT 20;"
```

Read the result:

- **One row** — the gateway sent once. The duplicate did not come from here.
  Two deliveries of one message is a WhatsApp-side event.
- **Two rows with different `idempotency_key`** — Laravel asked twice, with two
  different keys. **This is a Laravel bug**, and the gateway behaved correctly:
  two different keys mean two different intended messages.
- **Two rows with the same `idempotency_key`** — this should be impossible; the
  column is unique. Capture the output and escalate.

### Step 3 — Count the scale

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres psql -U eagleto -d baileys_gateway -c "SELECT recipient, kind, count(*) FROM gateway_messages WHERE accepted_at > now() - interval '24 hours' GROUP BY recipient, kind HAVING count(*) > 1 ORDER BY count(*) DESC LIMIT 20;"
```

Legitimate repeats exist — the same person can be in two campaigns. Look for a
pattern: many recipients, each with exactly two, in a narrow time window. That
is a retry loop in Laravel.

### Step 4 — If it is Laravel

Give the developer:

- the `idempotency_key` values from step 2
- the `client_message_id` values, which are Laravel's own references
- the time window

The fix is on the Laravel side: reuse the same idempotency key when retrying a
send, rather than generating a new one. `GET /v1/messages/by-idempotency-key/{key}`
exists exactly so Laravel can check whether a send landed before deciding to
retry.

### Step 5 — Stop the bleeding

If sends are actively duplicating, pause the affected number from Eagleto. That
stops sending without unlinking the number, and it is reversible.
