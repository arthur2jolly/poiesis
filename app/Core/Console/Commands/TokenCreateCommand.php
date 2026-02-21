<?php

namespace App\Core\Console\Commands;

use App\Core\Models\User;
use App\Core\Services\TokenService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TokenCreateCommand extends Command
{
    protected $signature = 'token:create
        {user}
        {--name=default}
        {--expires= : Duration like 30d, 6h, or never}';

    protected $description = 'Create an API token for a user';

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

        $expiresAt = $this->parseExpires($this->option('expires'));

        if ($expiresAt === false) {
            return self::FAILURE;
        }

        $result = $this->tokenService->generate($user, $this->option('name'), $expiresAt ?: null);

        $raw = $result['token'];
        $border = str_repeat('*', strlen($raw) + 4);
        $expiry = $expiresAt ? $expiresAt->toDateTimeString() : 'never';

        $this->line($border);
        $this->line("* {$raw} *");
        $this->line($border);
        $this->warn('Store this token securely — it will not be shown again.');
        $this->info("Name: {$result['model']->name} | Expires: {$expiry}");

        return self::SUCCESS;
    }

    private function parseExpires(?string $expires): Carbon|false|null
    {
        if ($expires === null || $expires === 'never') {
            return null;
        }

        if (preg_match('/^(\d+)d$/', $expires, $m)) {
            return Carbon::now()->addDays((int) $m[1]);
        }

        if (preg_match('/^(\d+)h$/', $expires, $m)) {
            return Carbon::now()->addHours((int) $m[1]);
        }

        $this->error("Invalid --expires format: \"{$expires}\". Use Nd, Nh, or never.");

        return false;
    }
}
