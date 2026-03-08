<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TokenService;
use App\Core\Support\Role;
use Illuminate\Console\Command;

class UserCreateCommand extends Command
{
    protected $signature = 'user:create {--tenant= : Tenant slug (required)} {--role=4 : User role (1=Administrator, 2=Manager, 3=Developer, 4=Viewer)}';

    protected $description = 'Create a new user with a password';

    public function __construct(private readonly TokenService $tokenService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantSlug = $this->option('tenant');

        if ($tenantSlug === null) {
            $this->error('The --tenant option is required.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant not found: {$tenantSlug}");

            return self::FAILURE;
        }

        $name = $this->ask('Username');
        $password = $this->secret('Password');
        $role = (int) $this->option('role');

        if (empty($password)) {
            $this->error('Password cannot be empty.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters long.');

            return self::FAILURE;
        }

        if (! Role::isValid($role)) {
            $this->error("Invalid role: {$role}. Valid roles: 1 (Administrator), 2 (Manager), 3 (Developer), 4 (Viewer)");

            return self::FAILURE;
        }

        $user = User::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'password' => $password,
            'role' => $role,
        ]);

        $this->info("User created: {$user->id}");

        if (! $this->confirm('Generate a token now?', true)) {
            return self::SUCCESS;
        }

        $tokenName = $this->ask('Token name', 'default');
        $result = $this->tokenService->generate($user, $tokenName);

        $raw = $result['token'];
        $border = str_repeat('*', strlen($raw) + 4);

        $this->line($border);
        $this->line("* {$raw} *");
        $this->line($border);
        $this->warn('Store this token securely — it will not be shown again.');

        $this->info("Summary: user={$user->name}, token={$result['model']->name}");

        return self::SUCCESS;
    }
}
