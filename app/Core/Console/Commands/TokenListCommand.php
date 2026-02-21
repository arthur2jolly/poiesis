<?php

namespace App\Core\Console\Commands;

use App\Core\Models\ApiToken;
use App\Core\Models\User;
use App\Core\Services\TokenService;
use Illuminate\Console\Command;

class TokenListCommand extends Command
{
    protected $signature = 'token:list {user}';

    protected $description = 'List API tokens for a user';

    public function __construct(private readonly TokenService $tokenService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $user = User::where('name', $this->argument('user'))->first();

        if ($user === null) {
            $this->error("User not found: {$this->argument('user')}");

            return self::FAILURE;
        }

        $tokens = $this->tokenService->listForUser($user);

        $this->table(
            ['ID', 'Name', 'Created At', 'Expires At', 'Last Used At'],
            $tokens->map(fn (ApiToken $t) => [
                $t->id,
                $t->name,
                $t->created_at->toDateTimeString(),
                $t->expires_at?->toDateTimeString() ?? 'never',
                $t->last_used_at?->toDateTimeString() ?? 'never',
            ])
        );

        return self::SUCCESS;
    }
}
