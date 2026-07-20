<?php

return [

    // Base URL of the eagleto-baileys-gateway service. Reach it over the
    // internal network or a reverse proxy — the gateway must never be exposed
    // publicly.
    'base_url' => env('BAILEYS_GATEWAY_URL', ''),

    // Must match LARAVEL_API_KEY in the gateway's environment.
    'api_key' => env('BAILEYS_GATEWAY_API_KEY', ''),

    // Must match LARAVEL_SIGNING_SECRET. Signs every outbound request.
    'signing_secret' => env('BAILEYS_GATEWAY_SIGNING_SECRET', ''),

    // Must match WEBHOOK_SIGNING_SECRET. Verifies every inbound webhook.
    // A webhook whose signature does not verify is rejected, never processed.
    'webhook_secret' => env('BAILEYS_WEBHOOK_SECRET', ''),

    // Reject a webhook whose timestamp is further away than this, in seconds.
    // Bounds how long a captured delivery stays replayable.
    'webhook_max_skew' => (int) env('BAILEYS_WEBHOOK_MAX_SKEW', 300),
];
