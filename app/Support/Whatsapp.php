<?php

namespace App\Support;

use App\Contracts\WhatsappGateway;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use App\Services\OpenWaService;

/**
 * Resolves the WhatsApp engine to use — Evolution (Baileys) or WebJs
 * (whatsapp-web.js) — and returns the matching WhatsappGateway driver.
 *
 * Selection order:
 *   1. The device's own snapshotted `driver` (a connected number keeps its engine
 *      even if the tenant later flips the default).
 *   2. The tenant's `whatsapp_driver` default.
 *   3. 'evolution' (back-compat for pre-migration rows).
 *
 * Call sites resolve the shared gateway through this class.
 */
class Whatsapp
{
    /** The tenant default engine when a row/column is empty. */
    public const DEFAULT_DRIVER = 'openwa';

    public static function forInstance(WhatsappInstance $instance): WhatsappGateway
    {
        $driver = $instance->driver ?: self::tenantDriver($instance->tenant);

        return OpenWaService::forInstance($instance);
    }

    public static function forTenant(?Tenant $tenant): WhatsappGateway
    {
        return OpenWaService::forTenant($tenant);
    }

    /** The engine a brand-new device for this tenant should be created on. */
    public static function driverForTenant(?Tenant $tenant): string
    {
        return self::tenantDriver($tenant);
    }

    private static function tenantDriver(?Tenant $tenant): string
    {
        return $tenant?->whatsapp_driver ?: self::DEFAULT_DRIVER;
    }
}
