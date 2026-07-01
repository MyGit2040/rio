# Eagle — WhatsApp Marketing SaaS

Multi-tenant WhatsApp marketing platform. Each sign-up gets an isolated workspace
with its own devices, contacts, templates and campaigns. Messages are sent through a
self-hosted [Evolution API](https://github.com/EvolutionAPI/evolution-api) (Baileys) engine.

> **Compliance stance.** Eagle deliberately ships **no ban-evasion features** — no
> random/tracking-token injection, no "familiar-number" trust-faking, no session export,
> no auto-reroute-around-block. What it does provide is legitimate deliverability hygiene:
> rate limits, per-device caps, warm-up, quiet hours, opt-out handling and a suppression list.

## Stack

- Laravel 12 · PHP 8.3+
- Blade + Tailwind CSS (Vite) + Alpine.js
- Breeze auth (+ TOTP / email-OTP 2FA, reCAPTCHA v3)
- Queue: `database` driver · Scheduler for drips & scheduled campaigns
- Evolution API engine (Docker) for the actual WhatsApp connection

## Features / modules

| Area | Modules |
|------|---------|
| **Devices** | Multi-device (QR + pairing code), per-device daily cap, warm-up ramp, privacy controls, number-health dashboard |
| **Contacts** | Import (CSV), WhatsApp-number verify (throttled), groups, tags/segments, custom merge fields, profile + activity timeline |
| **Templates** | Text · media · poll · buttons · carousel · AI variant generation · upload (not URL) attachments · clone |
| **Campaigns** | Multi-device sticky routing, spintax, A/B variants, min/max delay, sleep-after-N, scheduling, retry-failed, test-send, CSV export, A/B & link-click reports |
| **Sequences** | Drip / follow-up steps with per-step delay, enroll by group, minute scheduler |
| **Inbox** | Two-way conversations + manual reply · hook-number reply forwarding |
| **Auto-reply** | Keyword chatbot rules |
| **Compliance** | Opt-out keywords, suppression (do-not-contact) list, quiet hours |
| **Analytics** | Reports overview, per-campaign A/B, link-click tracking |
| **Platform** | Media library, outbound webhooks (HMAC-signed), audit log, REST API tokens, backup/restore, per-workspace SMTP & branding, AI keys (ChatGPT/Gemini/Claude) |
| **Plans & billing** | Database-driven subscription plans managed in **Super-Admin → Plans** (name, monthly/annual price, usage limits, feature list, popular/default flags); owners self-switch on the Billing page; limits (devices/contacts/monthly messages) enforced live by `PlanLimit` |
| **Lists** | Tick-box bulk actions on every list (delete, and add/remove-group + opt-out on Contacts) via one shared `bulkSelect()` helper; collapsible Workspace sub-menu; per-page "?" help |

All modules are built and covered by `tests/Feature/AppSmokeTest.php` (113 tests).

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run dev          # keep running for assets
php artisan serve    # http://127.0.0.1:8000
```

Register at `/register` — the first sign-up creates your workspace and an **owner** account.

To exercise sending, run the Evolution engine (see `deploy/evolution/docker-compose.yml`)
and set its URL + API key under **Settings → WhatsApp engine**.

Run the queue + scheduler in dev:

```bash
php artisan queue:work
php artisan schedule:work
```

## Deployment (Webuzo VPS · eco.texon.me)

```bash
cd ~/public_html/eco && git pull --ff-only && \
/usr/local/php83/bin/php $(command -v composer) install --no-dev -o && \
npm run build && \
/usr/local/php83/bin/php artisan migrate --force && \
/usr/local/php83/bin/php artisan config:cache && \
/usr/local/php83/bin/php artisan route:cache && \
/usr/local/php83/bin/php artisan view:cache && \
chown -R waba:waba ~/public_html/eco
```

**Cron (required)** — point both at PHP 8.3:

```cron
* * * * * cd ~/public_html/eco && /usr/local/php83/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd ~/public_html/eco && /usr/local/php83/bin/php artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

`schedule:run` drives scheduled campaigns (`campaigns:dispatch-due`) and drip sequences
(`sequences:dispatch`); `queue:work` drives campaign sends, number-verify and webhooks.

## Testing

```bash
php artisan test
```
