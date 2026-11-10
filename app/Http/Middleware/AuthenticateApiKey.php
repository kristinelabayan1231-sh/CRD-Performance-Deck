<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The external API: a key from Settings → API Keys as "Authorization: Bearer …" or "X-Api-Key: …", made for the
 * route's scope (AuthenticateApiKey::class.':mc'; retention when none is given).
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, string $scope = ApiKey::RETENTION): Response
    {
        $key = ApiKey::findActive($request->bearerToken() ?? $request->header('X-Api-Key'));

        if (! $key) {
            return response()->json(['message' => 'Unknown, revoked or missing API key.'], 401);
        }

        if ($key->scope !== $scope) {
            return response()->json(['message' => 'This key is not allowed to read this endpoint.'], 403);
        }

        // Saved at most once a minute: a report pulling many ranges shouldn't write on every call.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
