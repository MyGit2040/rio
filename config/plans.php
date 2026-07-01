<?php

return [

    /*
     | Subscription tiers. Limits are enforced by App\Services\PlanLimit.
     | A limit of 0 means "unlimited". Payment is handled off-platform for now
     | (owners switch plans manually / on request) — no gateway is wired.
     */

    'default' => 'free',

    'tiers' => [
        'free' => [
            'name'   => 'Free',
            'price'  => 0,
            'limits' => ['devices' => 1, 'contacts' => 500, 'monthly_messages' => 1000],
            'features' => ['1 WhatsApp number', 'Bulk campaigns', 'Auto-reply', 'Templates'],
        ],
        'pro' => [
            'name'   => 'Pro',
            'price'  => 29,
            'limits' => ['devices' => 5, 'contacts' => 25000, 'monthly_messages' => 50000],
            'features' => ['5 WhatsApp numbers', 'Drip sequences', 'Two-way inbox', 'Link tracking', 'Webhooks'],
        ],
        'business' => [
            'name'   => 'Business',
            'price'  => 99,
            'limits' => ['devices' => 0, 'contacts' => 0, 'monthly_messages' => 0],
            'features' => ['Unlimited numbers', 'Unlimited contacts', 'Unlimited messages', 'Priority support', 'API access'],
        ],
    ],

];
