<?php

namespace App\Support;

use App\Contracts\WhatsappGateway;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use App\Services\OpenWaService;

/**
 * Resolves the OpenWA gateway for tenant and device operations.
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
