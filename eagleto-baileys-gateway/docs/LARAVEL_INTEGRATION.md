# Laravel integration

For the developer working on the Eagleto side. It covers the two directions of
traffic, the event catalogue, and how gateway message statuses map onto
Laravel's own.

- **Laravel to gateway** — signed HTTP requests.
- **Gateway to Laravel** — signed webhooks.

The two use **different secrets and different signing formats**. They are not
symmetric, and treating them as if they were is the most likely way to spend an
afternoon on a 401.

| | Laravel to gateway | Gateway to Laravel |
|---|---|---|
| Secret | `LARAVEL_SIGNING_SECRET` | `WEBHOOK_SIGNING_SECRET` |
| Signed material | five fields joined by **newlines** | `timestamp` + **`.`** + raw body |
| Also required | API key, nonce | nothing else |

---

## 1. Laravel to gateway

### The four headers

Every request except `/health/live` and `/health/ready` must carry all four.

| Header | Value |
|---|---|
| `X-Eagleto-Key` | `LARAVEL_API_KEY` |
| `X-Eagleto-Timestamp` | Unix seconds, as a string |
| `X-Eagleto-Nonce` | Random, single use, `^[A-Za-z0-9_-]{8,128}$` |
| `X-Eagleto-Signature` | HMAC-SHA256, lowercase hex |

### The signature base string

Verified against `buildSignatureBase()` in
`src/security/request-signature.ts`.

Five fields, joined by **newline** characters (`\n`), in this exact order:

```
<timestamp>\n<nonce>\n<METHOD>\n<path>\n<rawBody>
```

> The separator is a newline. Not a dot, not a pipe, not an empty string. If
> you have seen this contract written elsewhere with `.` separators, that
> description is wrong — the source joins with `"\n"`.

Rules that matter:

- **`METHOD` is uppercase.** The gateway uppercases before comparing, so `get`
  and `GET` produce the same signature, but send it uppercase anyway.
- **`path` includes the query string.** Sign `/v1/webhooks?status=dead_letter&limit=50`,
  not `/v1/webhooks`. The query is inside the signature so a captured request
  cannot have `?limit=1` rewritten to `?limit=100000`.
- **`rawBody` is the exact bytes you send.** Serialise once, sign those bytes,
  send those bytes. Never re-encode between signing and sending: JSON key order
  and whitespace do not survive a decode/encode round trip and the signature
  will not match.
- **An empty body is an empty string.** For a GET, the fifth field is `''`.

### Signing in PHP

```php
<?php

namespace App\Services\Baileys;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class GatewayClient
{
    public function __construct(
        private string $baseUrl,        // https://gateway.internal
        private string $apiKey,         // LARAVEL_API_KEY
        private string $signingSecret,  // LARAVEL_SIGNING_SECRET
    ) {
    }

    public function post(string $path, array $payload): Response
    {
        // Serialise ONCE. These exact bytes are signed and these exact bytes
        // are sent. Re-encoding in between is the classic cause of a 401.
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->send('POST', $path, $rawBody);
    }

    public function get(string $path): Response
    {
        // No body means an empty fifth field, not a missing one.
        return $this->send('GET', $path, '');
    }

    private function send(string $method, string $path, string $rawBody): Response
    {
        $timestamp = (string) time();

        // 32 hex characters: inside the 8..128 length limit and within the
        // permitted charset. The gateway rejects anything outside
        // ^[A-Za-z0-9_-]{8,128}$ because a newline in the nonce could shift
        // the remaining fields of the signature base.
        $nonce = bin2hex(random_bytes(16));

        // Newline separated. $path must already include any query string.
        $base = implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($method),
            $path,
            $rawBody,
        ]);

        $signature = hash_hmac('sha256', $base, $this->signingSecret);

        return Http::withHeaders([
                'X-Eagleto-Key'       => $this->apiKey,
                'X-Eagleto-Timestamp' => $timestamp,
                'X-Eagleto-Nonce'     => $nonce,
                'X-Eagleto-Signature' => $signature,
                'Content-Type'        => 'application/json',
            ])
            ->withBody($rawBody, 'application/json')
            ->timeout(30)
            ->send($method, $this->baseUrl . $path);
    }
}
```

`withBody()` rather than `->post($url, $array)` is deliberate: passing an array
lets the HTTP client re-encode the payload, and the bytes it sends may then
differ from the bytes you signed.

### What the gateway checks, in order

1. Source IP, if `API_ALLOWED_IPS` is set. Otherwise skipped.
2. All four headers present. A missing one gives `missing_credentials`.
3. The API key matches, compared in constant time.
4. The signature verifies.
5. The timestamp is within `REQUEST_MAX_SKEW_SECONDS` (300) in **either**
   direction. A timestamp in the future is refused as firmly as an expired one.
6. The nonce has not been seen before.

The nonce is only spent after the signature verifies, so unsigned traffic
cannot burn nonces or fill the table.

### Reading a failure

| Status | `error.code` | Meaning |
|---|---|---|
| 401 | `missing_credentials` | One or more headers absent. |
| 401 | `stale_timestamp` | Clock skew. Check NTP on both servers. |
| 401 | `unauthorized` | Unknown key, bad signature, or a replayed nonce. |
| 403 | `ip_not_allowed` | Source address not in `API_ALLOWED_IPS`. |
| 400 | `invalid_json` | Body is not valid JSON. Reported only after authentication passes. |
| 503 | `nonce_store_unavailable` | Replay protection is down. Retry shortly. |

`unauthorized` is deliberately vague. Telling a caller "the key was right but
the signature was wrong" confirms that a leaked key is still live. The precise
reason is in the gateway's log, against the request id returned in the
`X-Request-Id` response header — quote that id when asking an operator to look.

Nothing here is a reason to retry immediately except `503`, which is an outage
rather than a verdict.

---

## 2. Gateway to Laravel

### The request

The gateway POSTs JSON to `LARAVEL_WEBHOOK_URL` (or a per-instance override).

Headers, verified against `deliverWebhook()` in
`src/webhooks/webhook-dispatcher.ts`:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Eagleto-Event-ID` | The event id. Same as `event_id` in the body. |
| `X-Eagleto-Timestamp` | Unix seconds, as a string |
| `X-Eagleto-Signature` | HMAC-SHA256, lowercase hex |

### The signature

Verified against `signedPayload()` in `src/webhooks/webhook-signer.ts`:

```
signature = HMAC-SHA256( WEBHOOK_SIGNING_SECRET, timestamp + "." + rawBody )
```

A dot. Two fields. This is **not** the same format as the inbound direction.

The timestamp is inside the signature rather than merely alongside it, so a
captured request cannot be replayed later with a fresh timestamp — changing it
invalidates the signature.

### The envelope

Verified against `WebhookEnvelope` in `src/types/index.ts`:

```json
{
  "event_id": "clx8k2p9q0000abcd1234efgh",
  "event_type": "message.delivered",
  "event_version": "1.0",
  "occurred_at": "2026-07-20T14:32:11.482Z",
  "instance_id": "clx8k1a2b0000wxyz9876mnop",
  "data": {},
  "metadata": {}
}
```

| Field | Notes |
|---|---|
| `event_id` | Unique per event. **Use it to de-duplicate.** |
| `event_type` | See the catalogue below. |
| `event_version` | `"1.0"` today. Treat an unknown version as unknown, do not guess. |
| `occurred_at` | When it happened, not when it was delivered. Under a backlog these differ by a lot. |
| `instance_id` | Null for gateway-level events, and after an instance is deleted. |
| `data` | The event body. Shape depends on `event_type`. |
| `metadata` | Whatever Laravel attached when sending. Echoed back untouched. |

### Verifying in PHP

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBaileysEvent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BaileysWebhookController
{
    /** Same window the gateway applies to inbound requests. */
    private const MAX_AGE_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        // The signature covers the bytes as received. getContent() gives them
        // unchanged; $request->all() would not.
        $rawBody   = $request->getContent();
        $timestamp = (string) $request->header('X-Eagleto-Timestamp', '');
        $signature = (string) $request->header('X-Eagleto-Signature', '');

        if ($timestamp === '' || $signature === '') {
            return response()->json(['error' => 'missing signature headers'], 401);
        }

        // 1. Freshness. Checked in both directions: a far-future timestamp is
        //    as suspicious as an expired one.
        if (abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            return response()->json(['error' => 'stale timestamp'], 401);
        }

        // 2. Signature. Note the dot separator — this direction is not the
        //    newline format used for outbound requests.
        $expected = hash_hmac(
            'sha256',
            $timestamp . '.' . $rawBody,
            config('services.baileys.webhook_secret'),
        );

        // hash_equals, never ===. A byte-by-byte early exit leaks how much of
        // a forged signature was right.
        if (! hash_equals($expected, $signature)) {
            Log::warning('Baileys webhook signature rejected', [
                'event_id' => $request->header('X-Eagleto-Event-ID'),
            ]);

            return response()->json(['error' => 'invalid signature'], 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || ! isset($payload['event_id'], $payload['event_type'])) {
            return response()->json(['error' => 'malformed payload'], 422);
        }

        // 3. De-duplicate. Delivery is at-least-once: a webhook that succeeds
        //    but whose response is lost will be sent again. Handling the same
        //    event twice must be harmless.
        $lock = Cache::add('baileys:event:' . $payload['event_id'], true, now()->addDays(2));

        if (! $lock) {
            // Already handled. 200 so the gateway marks it delivered and stops
            // retrying — this is a success, not an error.
            return response()->json(['status' => 'duplicate'], 200);
        }

        // 4. Answer immediately, work later. The gateway times out after
        //    WEBHOOK_TIMEOUT_MS (15s by default), and a slow endpoint turns
        //    into a retry storm during a busy campaign.
        ProcessBaileysEvent::dispatch($payload);

        return response()->json(['status' => 'accepted'], 200);
    }
}
```

Three more things on the Laravel side:

- **Exclude the route from CSRF.** A 419 dead-letters every event.
- **Do not put the route behind `auth`.** The gateway authenticates with a
  signature, not a session.
- **Do not read the body with `$request->all()` before verifying.** Verify
  against `getContent()` first.

### What the gateway does when Laravel fails

Verified against `src/webhooks/webhook-dispatcher.ts` and
`src/jobs/webhook.worker.ts`.

- Events are written to the database **before** any delivery is attempted. A
  Laravel outage delays events; it does not lose them.
- Any non-2xx response, or a timeout, is a failure and is retried.
- Retry delays, in seconds: **10, 30, 60, 300, 900, 3600**, then 3600 for every
  later attempt, each with up to 20% random jitter. The jitter matters: without
  it every event queued during an outage becomes due in the same instant and
  the recovering service is hit by the whole backlog at once.
- After `WEBHOOK_MAX_ATTEMPTS` (10) the event is marked `DEAD_LETTER` and is no
  longer retried. It needs an operator — see `RECOVERY_PLAYBOOK.md`.
- Requests time out after `WEBHOOK_TIMEOUT_MS` (15000 ms).
- Delivery is **at-least-once**, ordered oldest-first by `occurred_at` on a
  best-effort basis. Do not assume strict ordering, and do not assume exactly
  once. De-duplicate on `event_id`.

---

## 3. Event catalogue

All twenty-nine types, verified against `WEBHOOK_EVENT_TYPES` in
`src/types/index.ts`.

> **Six of the twenty-nine are declared but never emitted** by the current
> code: `instance.created`, `instance.pairing_code`, `instance.syncing`,
> `message.deleted`, `message.updated` and `call.rejected`. They are marked
> below. Do not build a Laravel handler that waits for one of them — either
> the emission is still to come, or the type is vestigial. Confirm which before
> relying on it.
>
> The other twenty-three are emitted from `src/baileys/event-handler.ts`,
> `message-sender.ts`, `poll-handler.ts`, `socket-manager.ts` and
> `jobs/maintenance.worker.ts`. The `data` contents listed below are indicative
> — read the emitting call site for the exact keys of any event you parse.

### Instance lifecycle

| Event | When | Carries |
|---|---|---|
| `instance.created` | **Not emitted.** | — |
| `instance.qr` | A new QR code is issued. | The QR payload and its expiry. Treat as a credential. |
| `instance.pairing_code` | **Not emitted.** The code is returned in the pairing-code response instead. | — |
| `instance.authenticated` | WhatsApp accepted the login. | The linked phone number, when known. |
| `instance.syncing` | **Not emitted.** | — |
| `instance.ready` | Connected. **Still not sendable until the stabilization window passes.** | `ready_since`. |
| `instance.disconnected` | The connection dropped for a recoverable reason. | `classification`, `code`, `reason`, `recoverable`. |
| `instance.reconnect_wait` | Waiting before another attempt. | Attempt count, next attempt time. |
| `instance.logged_out` | Unlinked. **Needs a new QR.** | `classification`, `code`, `reason`, `recoverable`. |
| `instance.replaced` | Another session took over. | Same four fields. |
| `instance.restricted` | WhatsApp refused the account. **Stop using this number.** | Same four fields. |
| `instance.error` | Unrecoverable problem. | Error code and message. |

The last four share one emission site in `socket-manager.ts`, chosen by the
disconnect classification, so they carry an identical `data` shape.

Laravel should treat `instance.logged_out`, `instance.replaced`,
`instance.restricted` and `instance.error` as "stop scheduling work for this
number" — they are the four that will not fix themselves.

### Messages

| Event | When |
|---|---|
| `message.accepted` | Recorded by the gateway. Not sent yet. |
| `message.sent` | Handed to WhatsApp. |
| `message.server_ack` | WhatsApp's servers acknowledged it. |
| `message.delivered` | Reached the recipient's device. |
| `message.read` | The recipient read it. |
| `message.played` | The recipient played a voice or video note. |
| `message.failed` | It will not be delivered. Carries the reason. |
| `message.received` | An inbound message arrived. |
| `message.deleted` | **Not emitted.** |
| `message.updated` | **Not emitted.** |

Status events carry the `gateway_message_id` and the `metadata` supplied on the
original send, so an event can be attributed to a campaign and contact without
a lookup table.

**Inbound media is never inlined.** `message.received` carries a descriptor —
MIME type, size, SHA-256, a download URL and an expiry — and Laravel fetches
the bytes separately. That keeps events small and lets the gateway enforce size
and type limits before anything is stored. Download before `expires_at`.

### Polls

| Event | When |
|---|---|
| `poll.created` | A poll was sent. |
| `poll.vote_received` | Someone voted for the first time. |
| `poll.vote_changed` | Someone changed their vote. |
| `poll.vote_removed` | Someone withdrew their vote. |

The three are distinct on purpose: "changed their mind" and "voted" usually
deserve different handling.

### Calls

| Event | When |
|---|---|
| `call.received` | An incoming call. |
| `call.rejected` | **Not emitted.** |

### Gateway

| Event | When |
|---|---|
| `gateway.health_warning` | The gateway is degraded but running. Worth alerting on. |

---

## 4. Status mapping

### Gateway side (verified)

`MessageStatus` in `prisma/schema.prisma`:

| Status | Meaning |
|---|---|
| `ACCEPTED` | Recorded by the gateway. Nothing sent yet. |
| `SENT` | Handed to WhatsApp. |
| `SERVER_ACK` | WhatsApp's servers acknowledged it. |
| `DELIVERED` | Reached the recipient's device. |
| `READ` | The recipient read it. |
| `PLAYED` | The recipient played a voice or video note. |
| `FAILED` | It will not be delivered. |

Normal progression:

```
ACCEPTED -> SENT -> SERVER_ACK -> DELIVERED -> READ -> PLAYED
```

Every step after `SENT` depends on the recipient. `DELIVERED` never arrives if
their phone is off. `READ` never arrives if they have read receipts disabled —
which is a privacy setting, not a delivery failure. `PLAYED` only applies to
voice and video notes.

### Laravel side (confirm this)

> **Unconfirmed.** The Laravel application is not in this repository, so the
> real column and status names in `campaign_recipients` could not be checked.
> The table below is a proposal. Confirm the names against the Laravel schema
> before implementing, and change this document to match what is actually
> there.

| Gateway `MessageStatus` | Webhook | Proposed `campaign_recipients.status` |
|---|---|---|
| `ACCEPTED` | `message.accepted` | `queued` |
| `SENT` | `message.sent` | `sent` |
| `SERVER_ACK` | `message.server_ack` | `sent` |
| `DELIVERED` | `message.delivered` | `delivered` |
| `READ` | `message.read` | `read` |
| `PLAYED` | `message.played` | `read` |
| `FAILED` | `message.failed` | `failed` |

Notes on the proposal:

- `SERVER_ACK` maps to the same value as `SENT` because the difference —
  "handed over" versus "acknowledged by WhatsApp" — is rarely meaningful to a
  campaign report. Keep the gateway value in a separate column if you want it.
- `PLAYED` maps to `read` for the same reason. Playing implies reading.
- Statuses can arrive **out of order**, because delivery is at-least-once and
  retries reorder things. Never move a recipient backwards: if the row is
  already `read`, a late `delivered` event must not overwrite it. Rank the
  statuses and only apply an event that ranks higher.
- `failed` is the exception — it can arrive at any point and should always
  win, because it is terminal.

A worked version of that rule:

```php
private const RANK = [
    'queued'    => 0,
    'sent'      => 1,
    'delivered' => 2,
    'read'      => 3,
    'failed'    => 99,  // terminal, always wins
];

public function applyStatus(CampaignRecipient $recipient, string $incoming): void
{
    $current = self::RANK[$recipient->status] ?? 0;
    $next    = self::RANK[$incoming] ?? 0;

    // Late events are normal, not an error. Ignore anything that would move
    // the recipient backwards.
    if ($next <= $current) {
        return;
    }

    $recipient->update(['status' => $incoming]);
}
```

---

## 5. Sending

Sends return **202 Accepted**. That means the request was recorded, not that
WhatsApp delivered anything. Progress arrives later as webhooks.

```json
{
  "success": true,
  "status": "accepted",
  "gateway_message_id": "clx8m4n5p0000qrst2468uvwx",
  "client_message_id": "campaign-1180-contact-99213",
  "instance_id": "clx8k1a2b0000wxyz9876mnop"
}
```

`status` is `accepted` or `duplicate`. Both the field names and the status
values are verified against `acceptanceBody()` in
`src/api/routes/messages.routes.ts`.

Two refusals share the 409 status and must be told apart by `error.code`:

| `error.code` | Meaning | What Laravel should do |
|---|---|---|
| `idempotency_key_reused` | The key was used before with a **different** payload. | Fix the caller. Do not retry as-is — the gateway is telling you two different messages share one key. |
| `instance_not_sendable` | The number is unknown, disabled, not `READY`, or still inside its stabilization window. | Wait, or pick another number. The gateway will never reroute for you. |

### Idempotency

Every send carries an `idempotency_key` chosen by Laravel. The gateway stores
it with a hash of the payload.

- **Same key, same payload** — 202 with `status: "duplicate"` and the original
  `gateway_message_id`. Nothing is sent twice.
- **Same key, different payload** — refused. The two requests plainly meant
  different things, so silently serving the original would be wrong.
- **Unsure whether a send landed** — call
  `GET /v1/messages/by-idempotency-key/{key}` before retrying.

This is what makes an HTTP timeout safe. **Retry with the same key.** Do not
generate a new one — a new key is a new message, and the gateway will send it.

A good key is deterministic from what the message *is*, not from when it was
attempted. Something like `campaign:{id}:contact:{id}:attempt:{n}` where `n`
only changes when you genuinely mean to send again.

### Before sending

Check the instance is `READY` **and** past its stabilization window. Sending
into a settling session is refused, and repeatedly trying it is a good way to
get a number restricted.

---

## 6. Checklist

- [ ] `LARAVEL_API_KEY` identical on both sides.
- [ ] `LARAVEL_SIGNING_SECRET` identical on both sides.
- [ ] `WEBHOOK_SIGNING_SECRET` identical on both sides.
- [ ] `LARAVEL_WEBHOOK_URL` points at the real Laravel route.
- [ ] Clocks synchronised. Skew over 300 seconds fails every request.
- [ ] Outbound requests sign with **newlines**, and the path includes the query
      string.
- [ ] Outbound requests send the same bytes they signed.
- [ ] The webhook route is excluded from CSRF.
- [ ] The webhook route is not behind auth middleware.
- [ ] Webhooks verified with `hash_equals`, over `timestamp . '.' . $rawBody`.
- [ ] Webhooks de-duplicated on `event_id`.
- [ ] Webhook timestamps checked for freshness.
- [ ] The webhook endpoint answers in well under 15 seconds and queues the
      work.
- [ ] Statuses never move a recipient backwards.
- [ ] Retries reuse the same `idempotency_key`.
