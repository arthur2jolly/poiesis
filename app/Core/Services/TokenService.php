<?php

namespace App\Core\Services;

use App\Core\Models\ApiToken;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class TokenService
{
    /**
     * @return array{token: string, model: ApiToken}
     */
    public function generate(User $user, string $name, ?Carbon $expiresAt = null): array
    {
        $generated = ApiToken::generateRaw();

        $apiToken = $user->apiTokens()->create([
            'name' => $name,
            'token' => $generated['hash'],
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $generated['raw'],
            'model' => $apiToken,
        ];
    }

    public function revoke(string $tokenId): void
    {
        ApiToken::destroy($tokenId);
    }

    /**
     * @return Collection<int, ApiToken>
     */
    public function listForUser(User $user): Collection
    {
        return $user->apiTokens()->get();
    }
}
