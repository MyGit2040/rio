<?php

namespace App\Support;

/**
 * Resolves the "current tenant" for query scoping.
 *
 * In normal web requests the tenant comes from the authenticated user.
 * Queue jobs / webhooks have no auth user, so they wrap their work in
 * Tenancy::run($tenantId, fn () => ...) to set an explicit override.
 */
class Tenancy
{
    protected static ?int $override = null;

    public static function id(): ?int
    {
        // An authenticated web/session user always wins, so a stale override
        // (e.g. from API token auth or a queue job) can never leak into a request.
        if ($user = auth()->user()) {
            return $user->tenant_id;
        }

        return static::$override;
    }

    public static function set(?int $tenantId): void
    {
        static::$override = $tenantId;
    }

    /**
     * Run a callback bound to a specific tenant, restoring the previous context after.
     */
    public static function run(?int $tenantId, callable $callback): mixed
    {
        $previous = static::$override;
        static::$override = $tenantId;

        try {
            return $callback();
        } finally {
            static::$override = $previous;
        }
    }
}
