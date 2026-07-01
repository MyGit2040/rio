<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a workspace whose subscription is suspended or expired. Super-admins
 * and a small allow-list of routes (the notice page, logout, profile) are exempt
 * so a blocked user can still see why and sign out.
 */
class CheckSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $exempt = $request->routeIs('subscription.inactive', 'logout', 'profile.*');

        if (! $exempt && $user->tenant?->isBlocked()) {
            return redirect()->route('subscription.inactive');
        }

        return $next($request);
    }
}
