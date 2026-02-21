<?php

namespace App\Core\Console\Commands;

use App\Core\Models\User;
use Illuminate\Console\Command;

class UserUpdateCommand extends Command
{
    protected $signature = 'user:update {name} {--name= : New name} {--password= : New password}';

    protected $description = 'Update a user (name and/or password)';

    public function handle(): int
    {
        $user = User::where('name', $this->argument('name'))->first();

        if ($user === null) {
            $this->error("User not found: {$this->argument('name')}");

            return self::FAILURE;
        }

        $newName = $this->option('name');
        $newPassword = $this->option('password');

        if ($newName === null && $newPassword === null) {
            $this->error('No changes provided. Use --name and/or --password to update the user.');

            return self::FAILURE;
        }

        if ($newPassword !== null && empty($newPassword)) {
            $this->error('Password cannot be empty.');

            return self::FAILURE;
        }

        if ($newPassword !== null && strlen($newPassword) < 8) {
            $this->error('Password must be at least 8 characters long.');

            return self::FAILURE;
        }

        $updates = [];
        $changes = [];

        if ($newName !== null) {
            $updates['name'] = $newName;
            $changes[] = "name to \"{$newName}\"";
        }

        if ($newPassword !== null) {
            $updates['password'] = $newPassword;
            $changes[] = 'password';
        }

        $changeText = implode(' and ', $changes);
        if (! $this->confirm("Update {$changeText} for \"{$user->name}\"?", true)) {
            return self::SUCCESS;
        }

        $user->update($updates);

        $this->info("User updated: {$user->id}");

        return self::SUCCESS;
    }
}
