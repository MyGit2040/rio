# OpenWA setup

Eagle uses OpenWA Easy API for its WhatsApp connection and bulk campaign sends.

1. Copy `deploy/openwa/.env.example` to `deploy/openwa/.env` and set a strong API key.
2. Run `docker compose --env-file .env up -d` from `deploy/openwa`.
3. In Eagle **Settings -> WhatsApp engine**, enter `http://127.0.0.1:8080` (or the private Docker/VPS address), the same API key, and the same session ID.
4. Open **Devices**, add the named device, and scan the QR shown in the page with WhatsApp **Linked devices**.
5. Start Eagle's queue worker and scheduler. Campaign sends are queued and enforce the workspace's delay, quiet-hours, daily-cap, and opt-out safeguards.

The OpenWA port is an authenticated private service. Do not expose it directly to the public internet.
