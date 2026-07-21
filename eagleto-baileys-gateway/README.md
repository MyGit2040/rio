# Eagleto Baileys Gateway

This is the WhatsApp connection service for Eagleto. It runs as its own
service, separate from the Laravel application at rio.eagleto.com.

This document is written for the person who runs the server. You do not need to
read code to follow it. Every command is complete and can be pasted as-is. The
directory each command must be run from is shown above every command block.

---

## Contents

1. [What this service does](#1-what-this-service-does)
2. [Requirements](#2-requirements)
3. [First install](#3-first-install)
4. [The encryption key — read this](#4-the-encryption-key--read-this)
5. [Connecting a WhatsApp number](#5-connecting-a-whatsapp-number)
6. [The three Baileys packages](#6-the-three-baileys-packages)
7. [Day-to-day operations](#7-day-to-day-operations)
8. [Backup and restore](#8-backup-and-restore)
9. [Upgrading Baileys](#9-upgrading-baileys)
10. [Troubleshooting](#10-troubleshooting)
11. [Legal and acceptable use](#11-legal-and-acceptable-use)
12. [Further documents](#12-further-documents)

---

## 1. What this service does

The work is split between two systems. Keeping the split clear is what makes
each side simple to reason about when something goes wrong.

### This gateway owns

- WhatsApp connections (the sockets).
- Linking a number, by QR code or pairing code.
- Storing each WhatsApp session so it survives a restart.
- Sending messages that Laravel asks it to send.
- Receiving delivery receipts and incoming messages.
- Sending signed webhooks back to Laravel describing what happened.

### This gateway does not own

- Contacts and contact lists.
- Campaigns, templates and scheduling.
- Sending limits and throttling rules.
- Which WhatsApp number a campaign uses.
- Reporting and statistics.
- Users, permissions and billing.

All of that lives in Laravel. The gateway holds no copy of it.

The practical consequence: if a message went to the wrong person, or a campaign
sent at the wrong time, that is a Laravel question. If a number will not
connect, or a message was accepted but never delivered, that is a gateway
question.

### How the two talk

- Laravel calls the gateway over HTTP. Every call is signed.
- The gateway calls Laravel back with webhooks. Every webhook is signed.

Both directions are covered in `docs/LARAVEL_INTEGRATION.md`.

---

## 2. Requirements

**On the server:**

- Docker Engine 24 or newer, with the Docker Compose plugin.
- 2 CPU cores and 4 GB RAM as a starting point.
- 20 GB of disk. Most of it is the database and stored media.
- A reverse proxy (nginx, Caddy or Traefik) with a TLS certificate.

**If you run it without Docker:**

- Node.js 22 or newer (required — see `engines` in `package.json`).
- PostgreSQL 16.
- Redis 7.

> **Run this on Node.js, not Bun.** The gateway is verified only on the standard
> Node.js runtime, and the Docker image is built `FROM node:22`. Baileys keeps a
> long-lived WhatsApp WebSocket whose timing and edge behaviour differ under
> alternative runtimes; on Bun those differences have been observed to trigger
> spurious disconnect/reconnect loops that look like a ban signal to WhatsApp.
> Use Node.js in production.

**Network:**

- Outbound HTTPS to WhatsApp servers.
- Outbound HTTPS to your Laravel application.
- Inbound HTTPS from Laravel, through your reverse proxy.

The gateway container binds to `127.0.0.1` only. It is not reachable from the
internet unless you put a reverse proxy in front of it. That is deliberate:
this API controls live WhatsApp sessions.

---

## 3. First install

### Step 1 — Go to the project directory

```
cd /opt/eagleto-baileys-gateway
```

Use whatever directory you cloned or copied the project into. Every command
below assumes you are in it unless stated otherwise.

### Step 2 — Create your environment file

Directory: `/opt/eagleto-baileys-gateway`

```
cp .env.example .env
```

`.env.example` is the full list of settings, with a comment explaining each
one. Read it once before continuing.

### Step 3 — Generate the three secrets

You need three separate 64-character random values. Do not reuse one value for
more than one setting, and do not invent them by hand.

Directory: `/opt/eagleto-baileys-gateway`

```
echo "MASTER_ENCRYPTION_KEY=$(openssl rand -hex 32)"
echo "LARAVEL_SIGNING_SECRET=$(openssl rand -hex 32)"
echo "WEBHOOK_SIGNING_SECRET=$(openssl rand -hex 32)"
```

That prints three lines. Copy each value into the matching line in `.env`.

You also need an API key, which is a shared password between Laravel and the
gateway:

Directory: `/opt/eagleto-baileys-gateway`

```
echo "LARAVEL_API_KEY=$(openssl rand -hex 32)"
```

Put that in `.env` as well.

What each one is for:

| Setting | Purpose |
|---|---|
| `MASTER_ENCRYPTION_KEY` | Encrypts WhatsApp sessions, proxy passwords and webhook secrets in the database. Must be exactly 64 hex characters. |
| `LARAVEL_API_KEY` | Identifies Laravel to the gateway. |
| `LARAVEL_SIGNING_SECRET` | Laravel signs its requests with this. The gateway checks the signature. |
| `WEBHOOK_SIGNING_SECRET` | The gateway signs its webhooks with this. Laravel checks the signature. |

`LARAVEL_API_KEY`, `LARAVEL_SIGNING_SECRET` and `WEBHOOK_SIGNING_SECRET` must
be set to the same values in the Laravel application. `MASTER_ENCRYPTION_KEY`
is used only by the gateway — Laravel never needs it.

### Step 4 — Set the database password

The database password appears in two places and they must agree.

First generate one:

Directory: `/opt/eagleto-baileys-gateway`

```
openssl rand -hex 24
```

`POSTGRES_PASSWORD` is **not** in `.env.example`, because it belongs to the
database container rather than to the gateway. Add it to `.env` yourself:

```
POSTGRES_PASSWORD=<the password you just generated>
```

`docker-compose.yml` also reads `POSTGRES_USER` and `POSTGRES_DB`. Both have
sensible defaults (`eagleto` and `baileys_gateway`) that match the example
`DATABASE_URL`, so you only need to add them if you want different names.

Then make sure `DATABASE_URL` contains the same password:

```
DATABASE_URL=postgresql://eagleto:<the same password>@postgres:5432/baileys_gateway?schema=public
```

The two must match exactly. If they do not, the gateway starts and then fails
to authenticate against its own database.

### Step 5 — Check the remaining settings

Open `.env` and confirm these:

- `LARAVEL_WEBHOOK_URL` — the address the gateway posts events to. The example
  is `https://rio.eagleto.com/webhooks/baileys`. Confirm the real path with
  whoever built the Laravel side.
- `APP_NODE_ID` — any short name for this server, for example `gateway-1`. If
  you ever run more than one gateway, every one needs a **different** value.
  Two servers sharing a node id would each believe they own the same WhatsApp
  session.
- `MEDIA_PUBLIC_BASE_URL` — the address Laravel uses to download incoming
  media from this gateway.
- `BAILEYS_PACKAGE` — leave it at `v6` unless you have read section 6.

### Step 6 — Start the services

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose up -d --build
```

The first build takes a few minutes. Then check everything came up:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose ps
```

All three services should show `running` and the gateway should show
`(healthy)` within about a minute.

### Step 7 — Create the database tables

The gateway needs its tables before it can do anything.

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose run --rm migrate
```

That runs a one-off container that applies the migrations and exits. It is not
part of `docker compose up` and does not stay running.

(The gateway's own image carries production dependencies only, so it has no
database tooling inside it. The `migrate` service exists to do this job.)

> **Check this before your first install.** `prisma migrate deploy` applies
> migration files from `prisma/migrations/`. At the time this document was
> written that directory did not exist in the project — only `schema.prisma`
> did. If the command above reports that there is nothing to apply, the
> migrations have not been generated yet. Ask a developer to run
> `npm run prisma:migrate:dev` once, commit the generated `prisma/migrations/`
> directory, and rebuild. Do not work around this by creating tables by hand.

### Step 8 — Confirm it is alive

Directory: `/opt/eagleto-baileys-gateway`

```
curl -s http://127.0.0.1:3090/health/live
```

```
curl -s http://127.0.0.1:3090/health/ready
```

`/health/live` says the process is running. `/health/ready` says it can reach
the database and Redis. Both are open without a password, on purpose — the
thing that monitors them has no credentials to give.

Every other endpoint needs a signed request. You cannot usefully call them with
plain `curl`, and that is intended.

### Step 9 — Point your reverse proxy at it

Send HTTPS traffic to `127.0.0.1:3090`. Terminate TLS at the proxy. Do not
publish port 3090 to the internet directly.

Optionally, set `API_ALLOWED_IPS` in `.env` to a comma-separated list of the
addresses your Laravel server calls from, as a second lock on the door.

---

## 4. The encryption key — read this

> ## Warning
>
> **If you lose `MASTER_ENCRYPTION_KEY`, every WhatsApp number linked to this
> gateway must be linked again by scanning a new QR code.**
>
> The key encrypts the stored WhatsApp sessions. Without it, those sessions
> cannot be read back. A database backup does not help — the backup is
> encrypted with that same key.
>
> This applies if the key is lost, and equally if it is *changed*. Changing the
> key has the same effect as losing it.

### What to do about it

1. **Back the key up before you start using the gateway.** Store it in your
   password manager, in the same place as your other production credentials.
2. **Store it somewhere other than this server.** A key that only exists on the
   server it protects is not backed up.
3. **Store it separately from your database backups.** Someone who steals both
   at once has the data and the key to read it.
4. **Never change it on a running system** unless you accept that every number
   will need re-linking.

To read the current key:

Directory: `/opt/eagleto-baileys-gateway`

```
grep MASTER_ENCRYPTION_KEY .env
```

If the gateway starts logging `Could not decrypt stored data. The
MASTER_ENCRYPTION_KEY may have changed since it was written.`, the key in use
does not match the one that wrote the data. Stop and restore the correct key
before doing anything else — see `docs/RECOVERY_PLAYBOOK.md`.

---

## 5. Connecting a WhatsApp number

Both methods are normally driven from the Eagleto interface in Laravel. What
follows is what happens underneath, so you can tell where a stuck connection
got stuck.

A number can be linked two ways. Both end in the same place.

### QR code

1. Laravel registers the number with the gateway. State: `CREATED`.
2. Laravel asks the gateway to start it. State: `STARTING`.
3. The gateway opens a socket and WhatsApp returns a QR code. State:
   `QR_REQUIRED`.
4. Laravel displays the QR code. The user opens WhatsApp on the phone, goes to
   **Settings → Linked devices → Link a device**, and scans it.
5. The number links. State moves through `PAIRING` and `AUTHENTICATED` to
   `SYNCING`, and finally `READY`.
6. The gateway waits out a settling period — `INSTANCE_STABILIZATION_SECONDS`,
   60 seconds by default — before it will send anything.

A QR code expires after a short time. If nobody scans it, WhatsApp issues a
new one and the gateway stores that instead. Laravel reads the current code;
asking for it never restarts the connection.

### Pairing code

Use this when scanning is impractical.

1. Steps 1 and 2 as above.
2. Laravel asks for a pairing code and supplies the phone number.
3. The gateway returns an 8-character code. State: `PAIRING_CODE_REQUIRED`.
4. On the phone: **Settings → Linked devices → Link a device → Link with phone
   number instead**, then type the code.
5. From here it is identical to the QR flow.

### When is a number usable

Only in state `READY`, and only after the settling period has passed. `READY`
on its own is not enough. This is not caution for its own sake: a campaign
fired into a session that is still settling is a good way to get a number
restricted.

The full list of states, and what each one means, is in
`docs/STATE_MACHINE.md`.

---

## 6. The three Baileys packages

Baileys is the library that speaks the WhatsApp protocol. This gateway can run
three different versions of it. You choose with `BAILEYS_PACKAGE` in `.env`.

| Value | Package | What it is |
|---|---|---|
| `v6` | `@whiskeysockets/baileys@6.7.23` | The stable line. **The default. Use this unless you have a reason not to.** |
| `v7rc` | `@whiskeysockets/baileys@7.0.0-rc13` | A release candidate for the next major version. Not final. |
| `fork` | `@itsukichan/baileys@7.3.2` | A community fork of the 7.x line. **Not installed by default.** |

Both `v6` and `v7rc` are installed with the project, so switching between them
needs no installation step.

### About the fork

The fork is deliberately not installed with everything else.

The reason: it runs a script of its own during installation. Anything in that
script runs on your server, with your permissions, before anyone has decided
to trust it. So it is left out, and installing it is a separate, deliberate
act that someone has to choose to take after reviewing what that script does.

If you have completed that review and want it:

Directory: `/opt/eagleto-baileys-gateway`

```
npm run use:baileys:fork
```

> **Two things to know about that command.** It installs with `--no-save`, so
> the fork is not recorded in `package.json` and will not be present after a
> rebuild. In Docker that means a rebuilt image will not contain it and an
> instance set to `fork` will fail to start with a "could not load Baileys
> package" error. Confirm the intended way to install the fork into your image
> with a developer before depending on it in production.

### Switching packages

> ## Warning
>
> A WhatsApp session saved by one package is not guaranteed to be readable by
> another. Switching the package for a number that is already linked will
> require that number to be linked again with a new QR code.

The gateway records which package saved each session and refuses to open a
session with a different one. That refusal is a feature. Without it, a package
change would quietly corrupt sessions and numbers would drop out days later
for no visible reason.

The refusal looks like this in the logs:

```
Session for instance <id> was created with Baileys package 'v6' but 'v7rc' is
selected. Auth state is not guaranteed portable between Baileys lines...
```

**To switch safely, for a number that is not yet linked:** change
`BAILEYS_PACKAGE` in `.env` and restart. Nothing is at risk.

**To switch for a number that is already linked**, you must accept the
re-link:

1. Set `BAILEYS_PACKAGE` to the new value in `.env`.
2. Set `BAILEYS_ALLOW_PACKAGE_SWITCH=true` in `.env`.
3. Restart the gateway.
4. Expect every affected number to require a fresh QR scan.
5. **Set `BAILEYS_ALLOW_PACKAGE_SWITCH` back to `false`** and restart again.

Leaving that flag on in production means a future configuration mistake
silently invalidates live sessions instead of being caught.

There is a safer path for testing: an individual instance can be given its own
package, so one number can be trialled on a different version while everything
else stays put. That is set per instance from Laravel, not in `.env`.

---

## 7. Day-to-day operations

### Check health

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose ps
```

Directory: `/opt/eagleto-baileys-gateway`

```
curl -s http://127.0.0.1:3090/health/ready
```

`/health/ready` reports the level `ok`, `warning` or `critical`, with a check
for each dependency.

### Read the logs

Live, as they happen:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs -f baileys-gateway
```

The last 200 lines:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 200 baileys-gateway
```

Errors only:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 500 baileys-gateway | grep -i '"level":"error"'
```

Logs are JSON, one object per line. Secrets, WhatsApp credentials, QR codes and
message text are removed before writing. You will not find a customer's message
body in the logs, by design.

Every log line for one request carries the same `requestId`, and that id is
returned to Laravel in the `X-Request-Id` header. If Laravel reports a failed
call, ask for that id and search for it:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 5000 baileys-gateway | grep '<the request id>'
```

### Restart safely

A restart drops every WhatsApp socket. They reconnect from stored sessions —
no re-linking — but sends are interrupted while it happens. Do it when
campaigns are not running.

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose restart baileys-gateway
```

The container is given 30 seconds to shut down cleanly. That time is used to
finish the webhook it is delivering and close its database connections. Do not
shorten it and do not `kill -9`: a hard kill can leave a webhook mid-delivery
and a session mid-write.

To stop everything:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose down
```

`docker compose down` keeps your data. The named volumes survive. Do **not**
add `-v` unless you intend to erase the database, which erases every WhatsApp
session with it.

### Pause and resume a number

Pausing stops a number sending, without unlinking it. Use it when a number
looks unhealthy and you want it out of rotation while you investigate.

Both actions are done from the Eagleto interface in Laravel, which calls the
gateway. There is no supported command-line equivalent, because the two systems
would then disagree about whether the number is in use.

Pause keeps the session. Resume reconnects without a QR. Logout does not — it
unlinks the number and a new QR is required.

### Update to a new version of the gateway

Directory: `/opt/eagleto-baileys-gateway`

```
git pull
```

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose up -d --build
```

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose run --rm migrate
```

Take a backup first. See the next section.

---

## 8. Backup and restore

Two things must be backed up, and **both are needed to recover**:

1. The PostgreSQL database — sessions, messages, webhook queue.
2. `MASTER_ENCRYPTION_KEY` — without it the backup cannot be read.

A database backup without the key is not a backup. It restores rows that
nothing can decrypt.

### Take a backup

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres pg_dump -U eagleto -d baileys_gateway -Fc > /var/backups/eagleto-gateway-$(date +%F-%H%M).dump
```

Then confirm the file exists and is not empty:

Directory: `/var/backups`

```
ls -lh /var/backups/eagleto-gateway-*.dump
```

Copy the dump off this server. A backup that only exists on the machine it came
from does not survive that machine.

### Automate it

Directory: any

```
sudo crontab -e
```

Add this line, which runs at 02:15 every day and keeps 14 days:

```
15 2 * * * cd /opt/eagleto-baileys-gateway && docker compose exec -T postgres pg_dump -U eagleto -d baileys_gateway -Fc > /var/backups/eagleto-gateway-$(date +\%F).dump && find /var/backups -name 'eagleto-gateway-*.dump' -mtime +14 -delete
```

The backslashes before the `%` signs are required. `cron` treats a bare `%` as
the end of the command.

### Back up the key

Directory: `/opt/eagleto-baileys-gateway`

```
grep MASTER_ENCRYPTION_KEY .env
```

Copy that value into your password manager. Do not write it into
`/var/backups` next to the database dumps — see section 4.

### Restore

> Restoring replaces the current database entirely. Only do this on a gateway
> you intend to overwrite.

Step 1 — stop the gateway, leaving the database running:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose stop baileys-gateway
```

Step 2 — confirm `MASTER_ENCRYPTION_KEY` in `.env` is the key that was in use
when the dump was taken. If it is not, restore that key first. Restoring the
database under a different key gives you unreadable sessions.

Step 3 — restore:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose exec -T postgres pg_restore -U eagleto -d baileys_gateway --clean --if-exists < /var/backups/eagleto-gateway-2026-07-20-0215.dump
```

Replace the filename with your own.

Step 4 — start the gateway:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose start baileys-gateway
```

Step 5 — watch the numbers reconnect:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs -f baileys-gateway
```

Sessions restored from a backup may be older than the ones WhatsApp last saw.
Some numbers may need re-linking. Check each one in Eagleto after a restore.

---

## 9. Upgrading Baileys

Baileys tracks a protocol that WhatsApp changes without notice, so upgrades
matter and upgrades carry risk. Work through this in order. Do not skip to the
end.

The risk being managed: a version change can make saved sessions unreadable,
and unreadable sessions mean every number needs a new QR scan. That is a
customer-visible outage, not a maintenance detail.

### The checklist

**1. Read the release notes.**
Look for anything about authentication state, session format, or the shape of
credentials. Those are the changes that force re-linking.

**2. Take a backup.**
Database and key, as in section 8. Verify the dump file exists and has a
sensible size. An upgrade without a verified backup has no way back.

**3. Test with saved sessions, in staging.**
Copy a production database backup to a staging gateway with the same
`MASTER_ENCRYPTION_KEY`, then start it on the new version. This is the test
that matters: it answers whether existing numbers survive.

**4. Test a fresh link.**
On staging, link a test number from nothing with a new QR code.

**5. Test sending — text, media and a poll.**
All three. Media and polls exercise code paths that a text message never
touches, and polls in particular depend on the stored creation payload.

**6. Test reconnection.**
Restart the staging gateway. Every number must come back on its own, without a
QR.

**7. Test logout.**
Log a test number out and confirm it moves to `LOGGED_OUT` cleanly.

**8. Roll out to one production instance first.**
Give a single number the new package as a per-instance override. Leave
everything else alone.

**9. Watch it for at least 24 hours.**
Session problems are rarely immediate. Watch for: disconnects, decryption
errors in the logs, messages accepted but never delivered, and any change in
how often the number reconnects.

**10. Roll out to the rest.**
Only after step 9 was quiet. Change `BAILEYS_PACKAGE`, restart, and watch the
fleet for another day.

If step 3 fails — old sessions do not open on the new version — the upgrade
means re-linking every number. That is a scheduled event you plan with the
business, not something to discover on a Friday afternoon.

---

## 10. Troubleshooting

Start with the logs in every case:

Directory: `/opt/eagleto-baileys-gateway`

```
docker compose logs --tail 200 baileys-gateway
```

| Symptom | Likely cause | What to do |
|---|---|---|
| QR code never appears | The socket never opened. Usually no outbound network, a broken proxy, or the instance is not in a state that produces a QR. | Check the logs for a connection error. Check the state — a QR only appears from `STARTING` into `QR_REQUIRED`. If a proxy is configured, test the proxy. Then restart the instance from Eagleto. |
| QR appears but expires before scanning | Normal. WhatsApp expires codes quickly. | Read the current code again — a new one is issued automatically. Do not restart the instance; that is slower and risks nothing useful. |
| Number keeps disconnecting and reconnecting | Unstable network, an unreliable proxy, or the same number is linked somewhere else. | Look up the disconnect code in `docs/DISCONNECT_REASONS.md` — it names the cause. Code 440 means another session took over: find and close it. Repeated 408 or 428 means network. |
| Number disconnects and does not come back | The reason was not recoverable — logged out, replaced, restricted, or a bad session. | Check the state. `LOGGED_OUT`, `REPLACED` and `RESTRICTED` all stop automatic retries on purpose; retrying a ban is futile and conspicuous. See `docs/DISCONNECT_REASONS.md`. |
| Log says `PackageMismatchError` | `BAILEYS_PACKAGE` was changed after this number was linked. | Either set it back to the package named in the message, or accept a re-link — section 6. Setting it back is almost always the right move. |
| Webhooks not arriving in Laravel | Wrong URL, wrong shared secret, Laravel rejecting the signature, or Laravel unreachable. | Look at the event list in Eagleto or ask a developer to query the webhook queue. `last_status_code` and `last_error` name the cause. 401 or 403 means the signature failed — check `WEBHOOK_SIGNING_SECRET` matches on both sides. A timeout means Laravel is slow or down. |
| Events stuck in `RETRY_WAIT` | Laravel is failing or unreachable. The gateway is backing off and will retry. | Fix Laravel. Delivery resumes on its own. Nothing is lost: events are saved before any delivery is attempted, so an outage is a delay. |
| Events in `DEAD_LETTER` | Delivery failed `WEBHOOK_MAX_ATTEMPTS` times (10 by default). Retries have stopped. | Find the cause in `last_error`, fix it, then replay those events. See `docs/RECOVERY_PLAYBOOK.md`. Replay resets the attempt count. |
| Messages accepted but never delivered | Accepted means recorded, not sent. The number may not be sendable, or the send failed after acceptance. | Check the instance is `READY` and past its settling period. Then look for a `message.failed` event, which carries the reason. |
| All numbers disconnected at once | The gateway restarted, lost network, or lost Redis. | Check the container is running and `/health/ready`. If it is up and reconnecting, wait. If not, `docs/RECOVERY_PLAYBOOK.md`. |
| Gateway will not start | Almost always a bad `.env`. The startup check refuses to run with an invalid configuration rather than start half-working. | Read the log — it lists every problem at once, by name. The commonest is `MASTER_ENCRYPTION_KEY must be 64 hex characters`. |
| `Could not decrypt stored data` | `MASTER_ENCRYPTION_KEY` is not the key that wrote the data. | Stop. Restore the correct key. Do not re-link numbers until you are certain the original key is gone — section 4 and the playbook. |
| Database is down | Postgres container stopped, out of disk, or unhealthy. | `docker compose ps`, then `docker compose logs postgres`. Check free disk with `df -h`. The gateway reports `critical` on `/health/ready` and keeps retrying. Note that replay protection lives in the database, so while it is down, signed requests are refused with a 503 rather than let through unchecked. |
| Redis is down | Redis container stopped. | `docker compose logs redis`. Redis holds the ownership leases that stop two gateway servers opening the same WhatsApp session. On a single-server install the effect is limited, but restore it promptly. |
| Laravel gets 401 on every call | Wrong API key, wrong signing secret, or clocks out of step. | If the error code is `stale_timestamp`, the two servers' clocks disagree by more than `REQUEST_MAX_SKEW_SECONDS` (300 by default) — fix NTP. Otherwise check `LARAVEL_API_KEY` and `LARAVEL_SIGNING_SECRET` match on both sides. |
| Laravel gets 403 `ip_not_allowed` | `API_ALLOWED_IPS` does not include the address Laravel calls from. | Add the address, or clear the setting to rely on the reverse proxy. Remember the address seen is the one that reaches the container. |

Deeper procedures are in `docs/RECOVERY_PLAYBOOK.md`.

---

## 11. Legal and acceptable use

Read this before connecting a business number.

**This is an unofficial integration.** It is not the WhatsApp Business API and
it is not endorsed by, affiliated with, or supported by WhatsApp or Meta. It
works by connecting as a linked device, the same way WhatsApp Web does.

**Using it carries risk to your number.** WhatsApp may restrict or permanently
ban any number, at any time, at its own discretion, with no warning and no
appeal that anyone can rely on. A restricted number may not be recoverable.

**This gateway cannot prevent that.** It has stabilization delays and
per-number controls, and none of them are a guarantee. No software on your side
can be one. Anyone who tells you otherwise is selling something.

**Only message people who agreed to be contacted.** Send to recipients who
have given consent. Honour opt-outs immediately. Unsolicited messaging is the
fastest way to have a number banned, and in many jurisdictions it is also
unlawful.

**You are responsible for compliance.** That includes data protection law
(GDPR, UAE PDPL and any others that apply to you), telecommunications and
marketing rules in every country you send to, and WhatsApp's own Terms of
Service and Business Messaging Policy.

**Recommendations:**

- Do not use a number you cannot afford to lose.
- Do not use a personal number.
- Keep consent records in Laravel, where they belong.
- Start slowly with a new number. Volume from a cold number attracts attention.
- Stop immediately if a number shows signs of restriction.

---

## 12. Further documents

| Document | What is in it |
|---|---|
| `docs/STATE_MACHINE.md` | All sixteen instance states, what each means, and which allow sending. |
| `docs/DISCONNECT_REASONS.md` | Every disconnect code, whether it recovers on its own, and what to do. |
| `docs/RECOVERY_PLAYBOOK.md` | Step-by-step procedures for the things that go wrong. |
| `docs/LARAVEL_INTEGRATION.md` | For developers: signing requests, verifying webhooks, event catalogue, status mapping. |
| `openapi.yaml` | The HTTP API. Note which parts are marked unconfirmed. |
| `.env.example` | Every setting, with an explanation of each. |
