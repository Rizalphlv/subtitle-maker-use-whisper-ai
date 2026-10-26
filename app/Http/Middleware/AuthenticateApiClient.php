<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $client = $token ? ApiClient::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first() : null;
        if (! $client) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $request->attributes->set('api_client', $client);
        $client->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
