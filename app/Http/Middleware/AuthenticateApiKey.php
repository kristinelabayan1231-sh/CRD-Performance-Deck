<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The external API: a key from Settings → API Keys as "Authorization: Bearer …" or "X-Api-Key: …".
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = ApiKey::findActive($request->bearerToken() ?? $request->header('X-Api-Key'));

        if (! $key) {
            return response()->json(['message' => 'Unknown, revoked or missing API key.'], 401);
        }

        // Saved at most once a minute: a report pulling many ranges shouldn't write on every call.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
