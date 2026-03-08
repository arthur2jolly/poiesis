<?php

namespace App\Core\Models;

use App\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $oauth_client_id
 * @property string $user_id
 * @property string $token
 * @property array<int, string>|null $scopes
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon $created_at
 * @property-read \App\Core\Models\OAuthClient $client
 * @property-read \App\Core\Models\User $user
 * @property-read \App\Core\Models\OAuthRefreshToken|null $refreshToken
 */
class OAuthAccessToken extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'oauth_access_tokens';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'oauth_client_id', 'user_id', 'token', 'scopes', 'expires_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'oauth_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refreshToken(): HasOne
    {
        return $this->hasOne(OAuthRefreshToken::class, 'access_token_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
