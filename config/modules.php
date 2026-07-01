<?php

return [

    /*
     | Gate-able feature modules. A workspace's `enabled_modules` is a list of these
     | keys; null/empty means "all enabled" (so existing workspaces aren't locked out).
     | `routes` maps a module to the route-name patterns it owns — used by the
     | ModuleAccess middleware and the sidebar to hide/deny disabled modules.
     */

    'devices'     => ['label' => 'Devices',        'routes' => ['devices.*']],
    'inbox'       => ['label' => 'Inbox',          'routes' => ['inbox.*']],
    'templates'   => ['label' => 'Templates',      'routes' => ['templates.*']],
    'media'       => ['label' => 'Media library',  'routes' => ['media.*']],
    'contacts'    => ['label' => 'Contacts',       'routes' => ['contacts.*']],
    'groups'      => ['label' => 'Groups',         'routes' => ['groups.*']],
    'campaigns'   => ['label' => 'Bulk messages',  'routes' => ['campaigns.*']],
    'sequences'   => ['label' => 'Drip sequences', 'routes' => ['sequences.*']],
    'chatbot'     => ['label' => 'Auto reply',     'routes' => ['chatbot.*']],
    'reports'     => ['label' => 'Reports',        'routes' => ['reports.*']],
    'health'      => ['label' => 'Number health',  'routes' => ['health.*']],
    'spam'        => ['label' => 'Spam checker',   'routes' => ['spam.*']],
    'orders'      => ['label' => 'Orders',         'routes' => ['invoices.*']],
    'suppression' => ['label' => 'Do-not-contact', 'routes' => ['suppressions.*']],
    'api'         => ['label' => 'REST API',       'routes' => ['api-tokens.*']],
    'webhooks'    => ['label' => 'Outbound webhooks', 'routes' => ['webhook-endpoints.*']],

];
