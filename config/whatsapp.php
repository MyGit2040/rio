<?php

return [
    // Self-hosted gateway endpoint. The implementation is deliberately named
    // after the CRM capability, not a particular third-party product.
    'base_url' => env('WHATSAPP_GATEWAY_BASE_URL', env('OPENWA_BASE_URL', '')),
    'api_key' => env('WHATSAPP_GATEWAY_API_KEY', env('OPENWA_API_KEY', '')),
    'session_id' => env('WHATSAPP_GATEWAY_SESSION_ID', env('OPENWA_SESSION_ID', '')),
    'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET', env('OPENWA_WEBHOOK_SECRET', '')),
    'flat_delay_seconds' => (int) env('WHATSAPP_FLAT_DELAY', env('OPENWA_FLAT_DELAY', 60)),
];
