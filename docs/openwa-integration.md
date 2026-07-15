# OpenWA Easy API integration

Eagle can use OpenWA Easy API as the WhatsApp engine for a workspace. OpenWA owns
the browser session; Eagle uses its authenticated HTTP API for sending and reads
the QR/status endpoints while linking the device.

## Start OpenWA

Run a separate Easy API process for the workspace and keep the session ID stable:

```powershell
npx @open-wa/wa-automate --port 8080 --api-key "replace-with-a-long-secret" --session-id sales
```

The OpenWA repository included with this workspace notes that v5 is alpha. Test
OpenWA in a non-production environment before enabling it for users. Keep the
process private behind a firewall or reverse proxy; do not expose its port publicly.

## Configure Eagle

1. Run `php artisan migrate` after PHP/Laravel is available.
2. Open **Settings -> WhatsApp engine**.
3. Select **OpenWA Easy API**.
4. Enter the Easy API URL, the `--api-key` value, and the exact `--session-id`.
5. Scan the QR OpenWA prints in its terminal (or shows in its dashboard), then
   add the connected session as one device in Eagle.

One OpenWA Easy API process exposes one named session, so an OpenWA URL can be
linked to one Eagle device. Run another OpenWA process with a different port and
session ID for each additional device.

## Current limitation

OpenWA v5's CLI webhook registration is not restored upstream. Eagle can send
messages and show connection state, but inbound replies and delivery receipts need
an OpenWA webhook/SSE bridge before they can populate Eagle's inbox automatically.
