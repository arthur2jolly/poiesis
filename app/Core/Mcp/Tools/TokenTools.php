<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\User;
use App\Core\Services\TokenService;
use App\Core\Support\Role;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class TokenTools implements McpToolInterface
{
    public function __construct(
        private readonly TokenService $tokenService,
    ) {}

    /**
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return [
            $this->createTokenDescription(),
            $this->listTokensDescription(),
            $this->revokeTokenDescription(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'create_token' => $this->createToken($params, $user),
            'list_tokens' => $this->listTokens($params, $user),
            'revoke_token' => $this->revokeToken($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>}
     */
    private function createTokenDescription(): array
    {
        return [
            'name' => 'create_token',
            'description' => 'Create an API token for a user (administrator only)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'user_name' => ['type' => 'string', 'description' => 'Username of the token owner'],
                    'name' => ['type' => 'string', 'description' => 'Token name (identifier)'],
                    'expires_in' => ['type' => 'string', 'description' => 'Expiration duration: 30d, 6h, or never (default: 30d)'],
                ],
                'required' => ['user_name', 'name'],
            ],
        ];
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>}
     */
    private function listTokensDescription(): array
    {
        return [
            'name' => 'list_tokens',
            'description' => 'List API tokens for a user (administrator only)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'user_name' => ['type' => 'string', 'description' => 'Username to list tokens for'],
                ],
                'required' => ['user_name'],
            ],
        ];
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>}
     */
    private function revokeTokenDescription(): array
    {
        return [
            'name' => 'revoke_token',
            'description' => 'Revoke an API token by ID (administrator only)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'token_id' => ['type' => 'string', 'description' => 'UUID of the token to revoke'],
                ],
                'required' => ['token_id'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function createToken(array $params, User $user): array
    {
        $this->ensureAdministrator($user);

        $targetUser = $this->resolveUser($params['user_name'], $user);
        $expiresAt = $this->parseExpiration($params['expires_in'] ?? '30d');

        $result = $this->tokenService->generate($targetUser, $params['name'], $expiresAt);

        return [
            'id' => $result['model']->id,
            'name' => $result['model']->name,
            'token' => $result['token'],
            'user' => $targetUser->name,
            'expires_at' => $result['model']->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    private function listTokens(array $params, User $user): array
    {
        $this->ensureAdministrator($user);

        $targetUser = $this->resolveUser($params['user_name'], $user);
        $tokens = $this->tokenService->listForUser($targetUser);

        return $tokens->map(fn ($token) => [
            'id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'created_at' => $token->created_at->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function revokeToken(array $params, User $user): array
    {
        $this->ensureAdministrator($user);

        $this->tokenService->revoke($params['token_id']);

        return ['revoked' => true, 'token_id' => $params['token_id']];
    }

    private function ensureAdministrator(User $user): void
    {
        if (! Role::isAdministrator($user->role)) {
            throw ValidationException::withMessages([
                'token' => ['Only administrators can manage API tokens.'],
            ]);
        }
    }

    private function resolveUser(string $name, User $authenticatedUser): User
    {
        $user = User::where('name', $name)
            ->where('tenant_id', $authenticatedUser->tenant_id)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'user_name' => ["User '{$name}' not found."],
            ]);
        }

        return $user;
    }

    private function parseExpiration(string $value): ?Carbon
    {
        if ($value === 'never') {
            return null;
        }

        if (preg_match('/^(\d+)d$/', $value, $matches)) {
            return Carbon::now()->addDays((int) $matches[1]);
        }

        if (preg_match('/^(\d+)h$/', $value, $matches)) {
            return Carbon::now()->addHours((int) $matches[1]);
        }

        throw ValidationException::withMessages([
            'expires_in' => ["Invalid expiration format '{$value}'. Use: 30d, 6h, or never."],
        ]);
    }
}
