# OpenWA setup

Eagle uses OpenWA Easy API for its WhatsApp connection and bulk campaign sends.

1. Run the modern OpenWA gateway at `http://127.0.0.1:2785/api` and create an API key in its dashboard.
2. In Eagle **Settings -> WhatsApp engine**, enter that API URL, its API key, and the session name to use.
4. Open **Devices**, add the named device, and scan the QR shown in the page with WhatsApp **Linked devices**.
5. Start Eagle's queue worker and scheduler. Campaign sends are queued and enforce the workspace's delay, quiet-hours, daily-cap, and opt-out safeguards.

The OpenWA port is an authenticated private service. Do not expose it directly to the public internet.
