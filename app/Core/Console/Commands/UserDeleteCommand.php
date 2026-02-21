<?php

namespace App\Core\Console\Commands;

use App\Core\Models\User;
use Illuminate\Console\Command;

class UserDeleteCommand extends Command
{
    protected $signature = 'user:delete {name}';

    protected $description = 'Delete a user';

    public function handle(): int
    {
        $user = User::withCount(['apiTokens', 'projects'])
            ->where('name', $this->argument('name'))
            ->first();

        if ($user === null) {
            $this->error("User not found: {$this->argument('name')}");

            return self::FAILURE;
        }

        $this->warn(
            "User \"{$user->name}\" has {$user->api_tokens_count} token(s)"
            ." and belongs to {$user->projects_count} project(s)."
        );

        if (! $this->confirm('Delete this user? This action is irreversible.', false)) {
            return self::SUCCESS;
        }

        $user->delete();

        $this->info('User deleted.');

        return self::SUCCESS;
    }
}
