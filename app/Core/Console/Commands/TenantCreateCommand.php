<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Tenant;
use App\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TenantCreateCommand extends Command
{
    protected $signature = 'tenant:create {name} {--slug=}';

    protected $description = 'Create a new tenant';

    public function handle(): int
    {
        $name = $this->argument('name');
        $slug = $this->option('slug') ?? Str::substr(Str::slug($name), 0, 63);

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Slug already taken: {$slug}");

            return self::FAILURE;
        }

        $tenant = Tenant::create(['slug' => $slug, 'name' => $name, 'is_active' => true]);

        $this->info("Tenant created: {$tenant->id} ({$tenant->slug})");

        if (! $this->confirm('Create an owner user now?', false)) {
            return self::SUCCESS;
        }

        $userName = $this->ask('Username');
        $password = $this->secret('Password');

        if (empty($password) || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => $userName,
            'password' => $password,
            'role' => 1,
        ]);

        $this->info("Owner user created: {$user->id} ({$user->name})");

        return self::SUCCESS;
    }
}
