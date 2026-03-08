<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\ApiToken;
use App\Core\Models\OAuthAccessToken;
use App\Core\Models\Tenant;
use App\Core\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBearer
{
    public function __construct(private readonly TenantManager $tenantManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->bearerToken();

        if ($rawToken === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $hash = hash('sha256', $rawToken);

        $apiToken = ApiToken::withoutTenantScope()->where('token', $hash)->first();

        if ($apiToken !== null) {
            if ($apiToken->isExpired()) {
                return response()->json(['message' => 'Unauthorized.'], 401);
            }

            if ($apiToken->tenant_id === null) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $tenant = Tenant::find($apiToken->tenant_id);
            if ($tenant === null || ! $tenant->is_active) {
                return response()->json(['message' => 'Tenant not found or inactive.'], 403);
            }

            $this->tenantManager->setTenant($tenant);
            $apiToken->recordUsage();
            Auth::setUser($apiToken->user);

            return $next($request);
        }

        $oauthToken = OAuthAccessToken::withoutTenantScope()->where('token', $hash)->first();

        if ($oauthToken !== null && ! $oauthToken->isExpired()) {
            $client = $oauthToken->client;

            if ($client === null || $client->tenant_id === null) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $tenant = Tenant::find($client->tenant_id);
            if ($tenant === null || ! $tenant->is_active) {
                return response()->json(['message' => 'Tenant not found or inactive.'], 403);
            }

            $this->tenantManager->setTenant($tenant);
            Auth::setUser($oauthToken->user);

            return $next($request);
        }

        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
