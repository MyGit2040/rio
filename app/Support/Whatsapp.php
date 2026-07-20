<?php

namespace App\Support;

use App\Contracts\WhatsappGateway;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use App\Services\BaileysGatewayDriver;
use App\Services\WhatsappGatewayService;

/**
 * Resolves the WhatsApp engine for tenant and device operations.
 *
 * Selection order:
 *   1. The device's own snapshotted `driver`. A linked number keeps the engine
 *      it was created on even if the workspace later changes its default —
 *      sessions are not portable between engines, so silently switching one
 *      would force an unexpected re-link.
 *   2. The workspace's `whatsapp_driver` default.
 *   3. DEFAULT_DRIVER.
 *
 * Every call site in the app (DeviceController, SendCampaignMessage,
 * SequenceService, WebhookController) resolves through here, so this is the
 * only place that needs to know an engine exists.
 */
class Whatsapp
{
    /** The engine used when a row or column is empty. */
    public const DEFAULT_DRIVER = 'openwa';

    /** Engines a workspace may select. */
    public const DRIVERS = ['openwa', 'baileys'];

    public static function forInstance(WhatsappInstance $instance): WhatsappGateway
    {
        return match ($instance->driver ?: self::tenantDriver($instance->tenant)) {
            'baileys' => BaileysGatewayDriver::forInstance($instance),
            default => WhatsappGatewayService::forInstance($instance),
        };
    }

    public static function forTenant(?Tenant $tenant): WhatsappGateway
    {
        return match (self::tenantDriver($tenant)) {
            'baileys' => BaileysGatewayDriver::forTenant($tenant),
            default => WhatsappGatewayService::forTenant($tenant),
        };
    }

    /** The engine a brand-new device for this workspace should be created on. */
    public static function driverForTenant(?Tenant $tenant): string
    {
        return self::tenantDriver($tenant);
    }

    public static function label(string $driver): string
    {
        return match ($driver) {
            'baileys' => 'Baileys gateway',
            default => 'OpenWA Easy API',
        };
    }

    private static function tenantDriver(?Tenant $tenant): string
    {
        $driver = $tenant?->whatsapp_driver ?: self::DEFAULT_DRIVER;

        // Guard against a stale or hand-edited value naming an engine that no
        // longer exists: fall back rather than fatal on an unknown match arm.
        return in_array($driver, self::DRIVERS, true) ? $driver : self::DEFAULT_DRIVER;
    }
}
