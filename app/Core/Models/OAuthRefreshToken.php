<?php

namespace App\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $access_token_id
 * @property string $token
 * @property Carbon $expires_at
 * @property bool $revoked
 * @property Carbon $created_at
 * @property-read OAuthAccessToken $accessToken
 */
class OAuthRefreshToken extends Model
{
    use HasUuids;

    protected $table = 'oauth_refresh_tokens';

    public const UPDATED_AT = null;

    protected $fillable = [
        'access_token_id', 'token', 'expires_at', 'revoked',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked' => 'boolean',
    ];

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(OAuthAccessToken::class, 'access_token_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }
}
