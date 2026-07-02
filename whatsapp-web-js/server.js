'use strict';

/**
 * eag whatsapp-web.js bridge
 * --------------------------------------------------------------------------
 * A small Express server that runs ONE whatsapp-web.js client per WhatsApp
 * instance (device) and exposes a REST contract Laravel's WebJsService calls.
 *
 * It translates whatsapp-web.js events into the SAME Baileys/Evolution-shaped
 * webhook envelope the Laravel WebhookController already parses, so the inbound
 * path (opt-out, suppression, chatbot, delivery/read receipts) is identical for
 * both engines.
 *
 * Sessions persist to SESSION_PATH so a container restart re-links without a new
 * QR scan. Runs alongside Evolution — the app picks the engine per device.
 */

const express = require('express');
const path = require('path');
const QRCode = require('qrcode');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');

const PORT = parseInt(process.env.PORT || '3000', 10);
const API_KEY = process.env.WEBJS_API_KEY || '';
const SESSION_PATH = process.env.SESSION_PATH || path.join(__dirname, '.wwebjs_auth');

const app = express();
app.use(express.json({ limit: '25mb' }));

// --- Auth: every request must present the shared key -----------------------
app.use((req, res, next) => {
    if (!API_KEY) return next(); // unset key = open (dev only)
    if (req.get('X-Api-Key') === API_KEY) return next();
    return res.status(401).json({ success: false, error: 'unauthorized' });
});

/**
 * instanceName -> { client, status, qr, pairingCode, webhookUrl }
 */
const instances = new Map();

const jid = (number) => (String(number).includes('@') ? String(number) : `${String(number).replace(/\D+/g, '')}@c.us`);

// --- Webhook: post an event to the app in Baileys shape --------------------
async function emit(name, event, data) {
    const rec = instances.get(name);
    if (!rec || !rec.webhookUrl) return;
    try {
        await fetch(rec.webhookUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event, instance: name, data }),
        });
    } catch (err) {
        console.error(`[${name}] webhook ${event} failed:`, err.message);
    }
}

// --- Client lifecycle ------------------------------------------------------
function buildClient(name) {
    const client = new Client({
        authStrategy: new LocalAuth({ clientId: name, dataPath: SESSION_PATH }),
        puppeteer: {
            headless: true,
            // Use the system Chromium in Docker (PUPPETEER_EXECUTABLE_PATH); falls
            // back to Puppeteer's bundled binary locally when the env var is unset.
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
            // Required to run Chromium as root inside a container. NOT stealth flags.
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
        },
    });

    client.on('qr', async (qr) => {
        const rec = instances.get(name);
        if (rec) rec.status = 'connecting';
        try {
            const dataUrl = await QRCode.toDataURL(qr); // -> data:image/png;base64,...
            if (rec) rec.qr = dataUrl;
            await emit(name, 'QRCODE_UPDATED', { qrcode: { base64: dataUrl } });
        } catch (err) {
            console.error(`[${name}] qr encode failed:`, err.message);
        }
    });

    client.on('ready', async () => {
        const rec = instances.get(name);
        if (rec) { rec.status = 'open'; rec.qr = null; }
        await emit(name, 'CONNECTION_UPDATE', { state: 'open' });
    });

    client.on('authenticated', () => {
        const rec = instances.get(name);
        if (rec) rec.status = 'open';
    });

    client.on('disconnected', async (reason) => {
        const rec = instances.get(name);
        if (rec) rec.status = 'close';
        await emit(name, 'CONNECTION_UPDATE', { state: 'close', reason: String(reason) });
    });

    // Inbound message -> messages.upsert (Baileys shape the app parses).
    client.on('message', async (msg) => {
        await emit(name, 'MESSAGES_UPSERT', {
            key: {
                remoteJid: msg.from,
                fromMe: msg.fromMe,
                id: msg.id && (msg.id.id || msg.id._serialized),
            },
            message: { conversation: msg.body || '' },
            pushName: (msg._data && msg._data.notifyName) || null,
        });
    });

    // Delivery / read receipts -> messages.update. ack: 2=delivered, 3=read, 4=played.
    client.on('message_ack', async (msg, ack) => {
        const map = { 2: 'DELIVERY_ACK', 3: 'READ', 4: 'PLAYED' };
        const status = map[ack];
        if (!status) return;
        await emit(name, 'MESSAGES_UPDATE', {
            key: { id: msg.id && (msg.id.id || msg.id._serialized) },
            status,
        });
    });

    return client;
}

async function startInstance(name, webhookUrl, number) {
    let rec = instances.get(name);
    if (rec && rec.client) {
        if (webhookUrl) rec.webhookUrl = webhookUrl;
        return rec;
    }

    rec = { client: null, status: 'connecting', qr: null, pairingCode: null, webhookUrl: webhookUrl || null };
    instances.set(name, rec);

    const client = buildClient(name);
    rec.client = client;
    client.initialize();

    // Link-by-code: request an 8-digit pairing code instead of a QR scan.
    if (number) {
        client.requestPairingCode(String(number).replace(/\D+/g, ''))
            .then((code) => { rec.pairingCode = code; })
            .catch((err) => console.error(`[${name}] pairing code failed:`, err.message));
    }

    return rec;
}

const requireInstance = (req, res) => {
    const rec = instances.get(req.params.name);
    if (!rec || !rec.client) {
        res.status(404).json({ success: false, error: 'instance not found' });
        return null;
    }
    return rec;
};

// --- Routes: instance lifecycle -------------------------------------------
app.post('/instances', async (req, res) => {
    const { instanceName, webhookUrl, number } = req.body || {};
    if (!instanceName) return res.status(422).json({ success: false, error: 'instanceName required' });
    const rec = await startInstance(instanceName, webhookUrl, number);
    return res.json({ instanceName, status: rec.status, qr: rec.qr, pairingCode: rec.pairingCode, token: null });
});

app.post('/instances/:name/connect', async (req, res) => {
    const { number } = req.body || {};
    const rec = await startInstance(req.params.name, null, number);
    return res.json({ status: rec.status, qr: rec.qr, pairingCode: rec.pairingCode });
});

app.post('/instances/:name/webhook', (req, res) => {
    const rec = requireInstance(req, res);
    if (!rec) return;
    rec.webhookUrl = (req.body && req.body.webhookUrl) || rec.webhookUrl;
    return res.json({ success: true });
});

app.get('/instances/:name/state', (req, res) => {
    const rec = instances.get(req.params.name);
    return res.json({ state: rec ? rec.status : 'close' });
});

app.delete('/instances/:name/logout', async (req, res) => {
    const rec = requireInstance(req, res);
    if (!rec) return;
    try { await rec.client.logout(); } catch (err) { /* already gone */ }
    rec.status = 'close';
    return res.json({ success: true });
});

app.delete('/instances/:name', async (req, res) => {
    const rec = instances.get(req.params.name);
    if (rec && rec.client) {
        try { await rec.client.destroy(); } catch (err) { /* ignore */ }
    }
    instances.delete(req.params.name);
    return res.json({ success: true });
});

// --- Lookups ---------------------------------------------------------------
app.post('/instances/:name/check-numbers', async (req, res) => {
    const rec = requireInstance(req, res);
    if (!rec) return;
    const numbers = (req.body && req.body.numbers) || [];
    const out = [];
    for (const n of numbers) {
        const digits = String(n).replace(/\D+/g, '');
        try {
            const id = await rec.client.getNumberId(digits);
            out.push({ number: digits, jid: id ? id._serialized : null, exists: !!id });
        } catch (err) {
            out.push({ number: digits, jid: null, exists: false });
        }
    }
    return res.json(out);
});

// Privacy read/write is not exposed by whatsapp-web.js — return empty so the UI
// degrades gracefully rather than erroring.
app.get('/instances/:name/privacy', (req, res) => res.json({}));
app.post('/instances/:name/privacy', (req, res) => res.json({ ok: false, error: 'privacy not supported on this engine' }));

// --- Sending ---------------------------------------------------------------
const delayMs = (d) => new Promise((r) => setTimeout(r, Math.max(0, (parseInt(d, 10) || 0) * 1000)));

app.post('/instances/:name/send/text', async (req, res) => {
    const rec = requireInstance(req, res);
    if (!rec) return;
    const { number, text, delay } = req.body || {};
    try {
        if (delay) await delayMs(delay);
        const sent = await rec.client.sendMessage(jid(number), String(text ?? ''));
        return res.json({ success: true, id: sent.id && (sent.id.id || sent.id._serialized) });
    } catch (err) {
        return res.status(500).json({ success: false, error: err.message });
    }
});

app.post('/instances/:name/send/media', async (req, res) => {
    const rec = requireInstance(req, res);
    if (!rec) return;
    const { number, media, caption, fileName, delay } = req.body || {};
    try {
        if (delay) await delayMs(delay);
        const attachment = /^https?:\/\//i.test(String(media))
            ? await MessageMedia.fromUrl(String(media), { unsafeMime: true })
            : new MessageMedia('application/octet-stream', String(media).replace(/^data:[^;]+;base64,/, ''), fileName || undefined);
        const sent = await rec.client.sendMessage(jid(number), attachment, { caption: caption || undefined });
        return res.json({ success: true, id: sent.id && (sent.id.id || sent.id._serialized) });
    } catch (err) {
        return res.status(500).json({ success: false, error: err.message });
    }
});

app.post('/instances/:name/send/poll', async (req, res) => {
    const rec = requireInstance(req, res);
    if (!rec) return;
    const { number, name: question, values, selectableCount, delay } = req.body || {};
    try {
        if (delay) await delayMs(delay);
        const { Poll } = require('whatsapp-web.js');
        const poll = new Poll(String(question || 'Poll'), values || [], {
            allowMultipleAnswers: (parseInt(selectableCount, 10) || 1) > 1,
        });
        const sent = await rec.client.sendMessage(jid(number), poll);
        return res.json({ success: true, id: sent.id && (sent.id.id || sent.id._serialized) });
    } catch (err) {
        return res.status(500).json({ success: false, error: err.message });
    }
});

// whatsapp-web.js has no reliable native buttons — render them as text so the
// message still delivers (parity with the Evolution carousel fallback).
app.post('/instances/:name/send/buttons', async (req, res) => {
    const rec = requireInstance(req, res);
    if (!rec) return;
    const { number, title, description, footer, buttons, delay } = req.body || {};
    try {
        if (delay) await delayMs(delay);
        let body = [title, description].filter(Boolean).join('\n\n');
        for (const b of buttons || []) {
            if (b.url) body += `\n${b.displayText || 'Link'}: ${b.url}`;
            else if (b.phoneNumber) body += `\n${b.displayText || 'Call'}: ${b.phoneNumber}`;
            else if (b.displayText) body += `\n• ${b.displayText}`;
        }
        if (footer) body += `\n\n${footer}`;
        const sent = await rec.client.sendMessage(jid(number), body.trim());
        return res.json({ success: true, id: sent.id && (sent.id.id || sent.id._serialized), fallback: true });
    } catch (err) {
        return res.status(500).json({ success: false, error: err.message });
    }
});

app.get('/health', (req, res) => res.json({ ok: true, instances: instances.size }));

app.listen(PORT, () => console.log(`whatsapp-web.js bridge listening on :${PORT}`));
