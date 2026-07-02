# eag whatsapp-web.js bridge

A small Express server that runs one `whatsapp-web.js` client per WhatsApp device
and exposes a REST contract the Laravel app's `WebJsService` calls. It runs
**alongside** Evolution — the app selects the engine per tenant.

- Speaks the Evolution-shaped webhook envelope, so Laravel's `WebhookController`
  handles both engines with no changes.
- Sessions persist to `SESSION_PATH` — a restart re-links without a new QR scan.

## Run locally

```bash
cp .env.example .env      # set WEBJS_API_KEY (must match the app's WEBJS_API_KEY)
npm install
npm start                 # listens on :3000
```

## Run in Docker

Use `../deploy/webjs/docker-compose.yml`:

```bash
cd ../deploy/webjs
echo "WEBJS_API_KEY=<same-as-app>" > .env
docker compose up -d --build
```

Then in the app `.env`: `WEBJS_BASE_URL=http://<host>:3000` and the matching
`WEBJS_API_KEY`.

Full contract + event mapping: [`../docs/whatsapp-webjs-integration.md`](../docs/whatsapp-webjs-integration.md).

## Note

This engine sends to **opted-in contacts** under the app's existing consent,
opt-out, suppression, daily-cap and warm-up rules — the same limits that apply to
Evolution. It is not a bypass for WhatsApp's sending restrictions.
