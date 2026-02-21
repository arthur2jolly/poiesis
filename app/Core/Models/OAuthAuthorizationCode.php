<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $oauth_client_id
 * @property string $user_id
 * @property string $code
 * @property string $redirect_uri
 * @property array<int, string>|null $scopes
 * @property string|null $code_challenge
 * @property string|null $code_challenge_method
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon $created_at
 * @property-read \App\Core\Models\OAuthClient $client
 * @property-read \App\Core\Models\User $user
 */
class OAuthAuthorizationCode extends Model
{
    use HasUuids;

    protected $table = 'oauth_authorization_codes';

    public const UPDATED_AT = null;

    protected $fillable = [
        'oauth_client_id', 'user_id', 'code', 'redirect_uri',
        'scopes', 'code_challenge', 'code_challenge_method', 'expires_at',
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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
