<?php

namespace App\Core\Console\Commands;

use App\Core\Models\User;
use Illuminate\Console\Command;

class UserListCommand extends Command
{
    protected $signature = 'user:list';

    protected $description = 'List all users';

    public function handle(): int
    {
        $users = User::withCount(['apiTokens', 'projects'])->get();

        $this->table(
            ['ID', 'Name', 'Tokens', 'Projects', 'Created At'],
            $users->map(fn (User $u) => [
                $u->id,
                $u->name,
                $u->api_tokens_count,
                $u->projects_count,
                $u->created_at->toDateTimeString(),
            ])
        );

        return self::SUCCESS;
    }
}
