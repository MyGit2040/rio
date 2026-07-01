<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates REST API requests by a bearer token and binds the request to
 * the token's workspace (tenant). No session — this is for other apps.
 */
class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if (! $plain) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = ApiToken::withoutGlobalScopes()
            ->where('token', hash('sha256', $plain))
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Invalid API token.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        // Bind every query in this request to the token's workspace.
        Tenancy::set($token->tenant_id);
        $request->attributes->set('tenant_id', $token->tenant_id);

        return $next($request);
    }
}
