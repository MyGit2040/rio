# whatsapp-web.js engine — integration notes

A second WhatsApp engine that runs **alongside** Evolution and is **selectable per
tenant**. Evolution (Baileys) and WebJs (whatsapp-web.js/Puppeteer) both implement
`App\Contracts\WhatsappGateway`; call sites resolve one through `App\Support\Whatsapp`.

> Scope note: this engine is for messaging **opted-in contacts** under the app's
> existing consent/opt-out/suppression rules. It is not a bypass for WhatsApp's
> sending limits — the daily caps, warm-up, quiet hours and suppression list all
> still apply exactly as they do for Evolution.

## Components

| Piece | File |
|---|---|
| Contract | `app/Contracts/WhatsappGateway.php` |
| Evolution driver | `app/Services/EvolutionApiService.php` (`implements WhatsappGateway`) |
| WebJs driver | `app/Services/WebJsService.php` |
| Resolver | `app/Support/Whatsapp.php` |
| Config | `config/webjs.php` |
| Migration | `database/migrations/2026_07_02_000010_add_whatsapp_driver_columns.php` |
| Node bridge | `whatsapp-web-js/` (server.js, Dockerfile, package.json) |
| Deploy | `deploy/webjs/docker-compose.yml` |

## Driver selection

1. `whatsapp_instances.driver` — snapshotted when the device is created. A linked
   number keeps its engine even if the tenant later flips the default.
2. `tenants.whatsapp_driver` — the tenant default (`evolution` | `webjs`).
3. Fallback `evolution` (back-compat).

`Whatsapp::forInstance($device)` / `Whatsapp::forTenant($tenant)` return the right
driver. Every driver returns identical array shapes, so controllers/jobs are
engine-agnostic.

## Webhook payload contract (bridge → app)

The bridge POSTs to the **same** endpoint Evolution uses —
`POST /webhooks/evolution/{secret}` (`webhooks.evolution`) — so
`WebhookController` handles both engines unchanged. Envelope:

```json
{ "event": "<EVENT>", "instance": "<instance_name>", "data": { ... } }
```

The bridge maps whatsapp-web.js events → the Baileys shapes the controller parses:

| whatsapp-web.js event | event sent | data shape the app reads |
|---|---|---|
| `qr` | `QRCODE_UPDATED` | `{ "qrcode": { "base64": "data:image/png;base64,…" } }` |
| `ready` | `CONNECTION_UPDATE` | `{ "state": "open" }` |
| `disconnected` | `CONNECTION_UPDATE` | `{ "state": "close" }` |
| `message` (inbound) | `MESSAGES_UPSERT` | `{ "key": {"remoteJid","fromMe","id"}, "message": {"conversation": "…"}, "pushName": "…" }` |
| `message_ack` (2/3/4) | `MESSAGES_UPDATE` | `{ "key": {"id":"…"}, "status": "DELIVERY_ACK"\|"READ"\|"PLAYED" }` |

`message_ack` → status: ack **2** = `DELIVERY_ACK` (→ delivered), **3** = `READ`,
**4** = `PLAYED` (→ read). `WebhookController::onMessageStatus` lowercases and maps
these to `delivered` / `read` on `campaign_recipients`.

Group / broadcast JIDs and non-text inbound (media/reaction) are ignored by the
controller exactly as with Evolution.

## REST contract (app → bridge)

`WebJsService` calls these; auth via `X-Api-Key: <WEBJS_API_KEY>`.

| Method | Purpose | Returns |
|---|---|---|
| `POST /instances` `{instanceName, webhookUrl, number?}` | start a client | `{status, qr, pairingCode}` |
| `POST /instances/:name/connect` `{number?}` | refresh QR / pairing | `{status, qr, pairingCode}` |
| `POST /instances/:name/webhook` `{webhookUrl}` | (re)register webhook | `{success}` |
| `GET /instances/:name/state` | connection state | `{state}` |
| `DELETE /instances/:name/logout` | unlink | `{success}` |
| `DELETE /instances/:name` | destroy client | `{success}` |
| `POST /instances/:name/check-numbers` `{numbers[]}` | on-WhatsApp check | `[{number,jid,exists}]` |
| `POST /instances/:name/send/text` `{number,text,delay?}` | send text | `{success,id}` |
| `POST /instances/:name/send/media` `{number,media,caption?,fileName?,delay?}` | send media | `{success,id}` |
| `POST /instances/:name/send/poll` `{number,name,values[],selectableCount?,delay?}` | send poll | `{success,id}` |
| `POST /instances/:name/send/buttons` | buttons → text fallback | `{success,id,fallback}` |

`WebJsService` normalises `{status,qr,pairingCode}` into Evolution's
`qrcode.base64` / `instance.state` / `hash.apikey` shapes so `DeviceController`
parses them unchanged.

## Setup

1. `php artisan migrate` (adds the driver columns).
2. Set `WEBJS_BASE_URL` + `WEBJS_API_KEY` in the app `.env`.
3. Bring up the bridge: `cd deploy/webjs && docker compose up -d --build` (with a
   `.env` holding the matching `WEBJS_API_KEY`).
4. Set the tenant's `whatsapp_driver = webjs` (or leave `evolution`).
5. Add a device → scan the QR (returned/pushed exactly like Evolution).

## Not supported on this engine

- **Privacy read/write** — whatsapp-web.js has no stable API; returns empty / a
  clear "not supported" note so the UI degrades gracefully.
- **Native buttons** — WhatsApp removed them for most senders; rendered as text
  (parity with the Evolution carousel fallback).
