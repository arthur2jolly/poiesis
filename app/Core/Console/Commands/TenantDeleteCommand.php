<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Tenant;
use Illuminate\Console\Command;

class TenantDeleteCommand extends Command
{
    protected $signature = 'tenant:delete {slug}';

    protected $description = 'Soft-delete a tenant by deactivating it';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Tenant not found: {$slug}");

            return self::FAILURE;
        }

        if (! $tenant->is_active) {
            $this->warn("Tenant '{$slug}' is already inactive.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Deactivate tenant '{$slug}'? Its users will lose access.", false)) {
            return self::SUCCESS;
        }

        $tenant->update(['is_active' => false]);
        $this->info("Tenant '{$slug}' has been deactivated.");

        return self::SUCCESS;
    }
}
