<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Tenant;
use App\Core\Models\User;
use Illuminate\Console\Command;

class UserListCommand extends Command
{
    protected $signature = 'user:list {--tenant= : Filter by tenant slug}';

    protected $description = 'List all users';

    public function handle(): int
    {
        $query = User::withoutTenantScope()->withCount(['apiTokens', 'projects']);

        $tenantSlug = $this->option('tenant');
        if ($tenantSlug !== null) {
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            if ($tenant === null) {
                $this->error("Tenant not found: {$tenantSlug}");

                return self::FAILURE;
            }
            $query->where('tenant_id', $tenant->id);
        }

        $users = $query->get();

        $this->table(
            ['ID', 'Name', 'Tenant', 'Tokens', 'Projects', 'Created At'],
            $users->map(fn (User $u) => [
                $u->id,
                $u->name,
                $u->tenant->slug ?? '—',
                $u->api_tokens_count,
                $u->projects_count,
                $u->created_at->toDateTimeString(),
            ])
        );

        return self::SUCCESS;
    }
}
