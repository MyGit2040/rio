<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Denies access to a feature route the workspace's plan doesn't include.
 * Maps the current route to a module via config/modules.php; unmapped routes
 * (dashboard, settings, billing, etc.) are always allowed.
 */
class ModuleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $tenant = $user->tenant;

        // No restrictions configured → allow everything.
        if (! $tenant || empty($tenant->enabled_modules)) {
            return $next($request);
        }

        foreach (config('modules') as $key => $cfg) {
            foreach ($cfg['routes'] as $pattern) {
                if ($request->routeIs($pattern)) {
                    abort_unless($tenant->allows($key), 403, 'This module is not included in your plan.');

                    return $next($request);
                }
            }
        }

        return $next($request);
    }
}
