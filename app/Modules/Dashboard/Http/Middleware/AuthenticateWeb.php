<?php

namespace App\Modules\Dashboard\Http\Middleware;

use App\Core\Models\User;
use App\Core\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWeb
{
    public function __construct(private readonly TenantManager $tenantManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check()) {
            return redirect('/login');
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        $user->loadMissing('tenant');

        if ($user->tenant === null || ! $user->tenant->is_active) {
            Auth::guard('web')->logout();

            return redirect('/login');
        }

        $this->tenantManager->setTenant($user->tenant);
        Auth::setUser($user);

        return $next($request);
    }
}
