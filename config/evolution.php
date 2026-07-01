<?php

return [

    // Base URL of the Evolution API engine, e.g. http://localhost:8080
    'base_url' => env('EVOLUTION_BASE_URL', ''),

    // Global API key (Evolution's AUTHENTICATION_API_KEY).
    'api_key' => env('EVOLUTION_API_KEY', ''),

    // Default WhatsApp integration channel.
    'integration' => env('EVOLUTION_INTEGRATION', 'WHATSAPP-BAILEYS'),

    // Flat, predictable delay (seconds) between transactional sends — load management, not evasion.
    'flat_delay_seconds' => (int) env('EVOLUTION_FLAT_DELAY', 60),

    // Events we subscribe each instance's webhook to.
    'webhook_events' => [
        'QRCODE_UPDATED',
        'CONNECTION_UPDATE',
        'MESSAGES_UPSERT',
        'MESSAGES_UPDATE',
        'SEND_MESSAGE',
    ],

    // Shared secret appended to the webhook URL so we can trust inbound calls.
    'webhook_secret' => env('EVOLUTION_WEBHOOK_SECRET', ''),
];
