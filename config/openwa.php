<?php

return [
    // Easy API URL, for example http://127.0.0.1:8080.
    'base_url' => env('OPENWA_BASE_URL', ''),

    // Must match OpenWA's --api-key value.
    'api_key' => env('OPENWA_API_KEY', ''),

    // Named session passed to OpenWA with --session-id. One Easy API process
    // exposes one session, so each tenant can link one OpenWA device per URL.
    'session_id' => env('OPENWA_SESSION_ID', ''),
];
