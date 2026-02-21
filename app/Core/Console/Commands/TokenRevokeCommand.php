<?php

namespace App\Core\Console\Commands;

use App\Core\Models\ApiToken;
use App\Core\Services\TokenService;
use Illuminate\Console\Command;

class TokenRevokeCommand extends Command
{
    protected $signature = 'token:revoke {token_id}';

    protected $description = 'Revoke an API token';

    public function __construct(private readonly TokenService $tokenService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $token = ApiToken::with('user')->find($this->argument('token_id'));

        if ($token === null) {
            $this->error("Token not found: {$this->argument('token_id')}");

            return self::FAILURE;
        }

        $this->line("Token: {$token->name} | User: {$token->user->name}");

        if (! $this->confirm('Revoke this token?', false)) {
            return self::SUCCESS;
        }

        $this->tokenService->revoke($token->id);

        $this->info('Token revoked.');

        return self::SUCCESS;
    }
}
