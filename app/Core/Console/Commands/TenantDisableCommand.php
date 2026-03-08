<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Tenant;
use Illuminate\Console\Command;

class TenantDisableCommand extends Command
{
    protected $signature = 'tenant:disable {slug}';

    protected $description = 'Disable a tenant';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Tenant not found: {$slug}");

            return self::FAILURE;
        }

        $tenant->update(['is_active' => false]);
        $this->info("Tenant '{$slug}' disabled.");

        return self::SUCCESS;
    }
}
