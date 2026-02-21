<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\ApiToken;
use App\Core\Models\OAuthAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBearer
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->bearerToken();

        if ($rawToken === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $hash = hash('sha256', $rawToken);

        $apiToken = ApiToken::where('token', $hash)->first();

        if ($apiToken !== null) {
            if ($apiToken->isExpired()) {
                return response()->json(['message' => 'Unauthorized.'], 401);
            }

            $apiToken->recordUsage();
            Auth::setUser($apiToken->user);

            return $next($request);
        }

        $oauthToken = OAuthAccessToken::where('token', $hash)->first();

        if ($oauthToken !== null && ! $oauthToken->isExpired()) {
            Auth::setUser($oauthToken->user);

            return $next($request);
        }

        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
