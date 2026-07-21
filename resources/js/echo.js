import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Live push (Laravel Reverb). Baked at BUILD time from VITE_REVERB_* in .env —
// when no key is configured the app ships without a socket client and every
// consumer (the Chats workspace) falls back to its polling cadence. Guarded so
// a misconfigured host/port can never break the page: Echo handles reconnects
// internally, and consumers must check `window.Echo` before subscribing.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
