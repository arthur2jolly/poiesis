<?php

namespace App\Core\Http\Middleware;

use App\Core\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantActive
{
    public function __construct(private readonly TenantManager $tenantManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenantManager->hasTenant()) {
            return response()->json(['message' => 'Tenant not found or inactive.'], 403);
        }

        return $next($request);
    }
}
