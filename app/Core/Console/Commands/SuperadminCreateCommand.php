<?php

namespace App\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperadminCreateCommand extends Command
{
    protected $signature = 'superadmin:create {--name=} {--password=}';

    protected $description = 'Create a superadmin account';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $password = $this->option('password') ?: $this->secret('Password');

        if (empty($password) || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        if (DB::table('superadmins')->where('name', $name)->exists()) {
            $this->error("Superadmin already exists: {$name}");

            return self::FAILURE;
        }

        DB::table('superadmins')->insert([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'password' => Hash::make($password),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("Superadmin created: {$name}");

        return self::SUCCESS;
    }
}
