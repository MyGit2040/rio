<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves the logo + brand name to show in the UI.
 *
 * Logged-in pages use the current workspace's uploaded logo. Guest pages
 * (login / register — no tenant yet) fall back to the primary workspace's
 * logo so a single-brand install still shows its logo on the login screen.
 */
class Branding
{
    protected static ?string $guestLogo = null;
    protected static bool $guestResolved = false;

    public static function logoPath(): ?string
    {
        if ($user = auth()->user()) {
            return data_get($user->tenant?->settings, 'logo_path');
        }

        if (! static::$guestResolved) {
            static::$guestResolved = true;
            static::$guestLogo = optional(
                Tenant::query()->where('settings', 'like', '%"logo_path"%')->orderBy('id')->first()
            )->settings['logo_path'] ?? null;
        }

        return static::$guestLogo;
    }

    public static function logoUrl(): ?string
    {
        $path = static::logoPath();

        return $path ? Storage::disk('public')->url($path) : null;
    }

    public static function brandName(): string
    {
        if ($user = auth()->user()) {
            return data_get($user->tenant?->settings, 'brand_name') ?: config('app.name', 'Eagle');
        }

        return config('app.name', 'Eagle');
    }
}
