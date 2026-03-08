<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Tenant;
use Illuminate\Console\Command;

class TenantListCommand extends Command
{
    protected $signature = 'tenant:list';

    protected $description = 'List all tenants';

    public function handle(): int
    {
        $tenants = Tenant::orderBy('created_at')->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Name', 'Active', 'Created At'],
            $tenants->map(fn (Tenant $t) => [
                $t->slug,
                $t->name,
                $t->is_active ? 'Yes' : 'No',
                $t->created_at->toDateTimeString(),
            ])
        );

        return self::SUCCESS;
    }
}
