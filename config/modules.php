<?php

return [

    /*
     | Gate-able feature modules. A workspace's `enabled_modules` is a list of these
     | keys; null/empty means "all enabled" (so existing workspaces aren't locked out).
     | `routes` maps a module to the route-name patterns it owns — used by the
     | ModuleAccess middleware and the sidebar to hide/deny disabled modules.
     */

    'devices'     => ['label' => 'Devices',          'desc' => 'Connect & manage WhatsApp numbers',        'routes' => ['devices.*']],
    'inbox'       => ['label' => 'Inbox',            'desc' => 'Two-way chat with contacts',               'routes' => ['inbox.*']],
    'templates'   => ['label' => 'Templates',        'desc' => 'Reusable message templates',               'routes' => ['templates.*']],
    'media'       => ['label' => 'Media library',    'desc' => 'Store & reuse images/files',               'routes' => ['media.*']],
    'contacts'    => ['label' => 'Contacts',         'desc' => 'Import, verify & tag contacts',            'routes' => ['contacts.*']],
    'groups'      => ['label' => 'Groups',           'desc' => 'Organise contacts into groups',            'routes' => ['groups.*']],
    'campaigns'   => ['label' => 'Bulk messages',    'desc' => 'Send bulk WhatsApp campaigns',             'routes' => ['campaigns.*']],
    'sequences'   => ['label' => 'Drip sequences',   'desc' => 'Automated follow-up sequences',            'routes' => ['sequences.*']],
    'chatbot'     => ['label' => 'Auto reply',       'desc' => 'Keyword auto-reply rules',                 'routes' => ['chatbot.*']],
    'reports'     => ['label' => 'Reports',          'desc' => 'Delivery & link-click analytics',          'routes' => ['reports.*']],
    'health'      => ['label' => 'Number health',    'desc' => 'Per-number usage & warm-up',               'routes' => ['health.*']],
    'spam'        => ['label' => 'Spam checker',     'desc' => 'Score message content quality',            'routes' => ['spam.*']],
    'orders'      => ['label' => 'Orders',           'desc' => 'WhatsApp shop orders/invoices',            'routes' => ['invoices.*']],
    'suppression' => ['label' => 'Do-not-contact',   'desc' => 'Blocked/opt-out number list',              'routes' => ['suppressions.*']],
    'api'         => ['label' => 'REST API',         'desc' => 'API tokens for integrations',              'routes' => ['api-tokens.*']],
    'webhooks'    => ['label' => 'Outbound webhooks', 'desc' => 'Push events to external systems',          'routes' => ['webhook-endpoints.*']],

];
