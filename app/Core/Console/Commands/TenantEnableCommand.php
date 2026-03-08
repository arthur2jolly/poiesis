<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Tenant;
use Illuminate\Console\Command;

class TenantEnableCommand extends Command
{
    protected $signature = 'tenant:enable {slug}';

    protected $description = 'Enable a tenant';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Tenant not found: {$slug}");

            return self::FAILURE;
        }

        $tenant->update(['is_active' => true]);
        $this->info("Tenant '{$slug}' enabled.");

        return self::SUCCESS;
    }
}
