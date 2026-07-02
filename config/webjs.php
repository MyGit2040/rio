<?php

return [

    // Base URL of the Node whatsapp-web.js bridge, e.g. http://whatsapp-web-engine:3000
    // (service name inside docker-compose) or http://127.0.0.1:3000 for local dev.
    'base_url' => env('WEBJS_BASE_URL', ''),

    // Shared bearer token the bridge requires on every request (X-Api-Key header).
    // Must match WEBJS_API_KEY inside the bridge container.
    'api_key' => env('WEBJS_API_KEY', ''),

    // Flat, predictable delay (seconds) between transactional sends — load management,
    // parity with config('evolution.flat_delay_seconds'). Not an evasion knob.
    'flat_delay_seconds' => (int) env('WEBJS_FLAT_DELAY', 60),

    // The bridge posts inbound events to the SAME endpoint Evolution uses
    // (webhooks.evolution), so WebhookController handles both engines unchanged.
    // We therefore reuse evolution.webhook_secret for the shared inbound secret.
];
