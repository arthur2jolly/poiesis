<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $user_id
 * @property string $name
 * @property string $client_id
 * @property string|null $client_secret
 * @property array<int, string> $redirect_uris
 * @property array<int, string> $grant_types
 * @property array<int, string>|null $scopes
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Core\Models\User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\OAuthAuthorizationCode> $authorizationCodes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\OAuthAccessToken> $accessTokens
 */
class OAuthClient extends Model
{
    use HasUuids;

    protected $table = 'oauth_clients';

    protected $fillable = [
        'user_id', 'name', 'client_id', 'client_secret',
        'redirect_uris', 'grant_types', 'scopes',
    ];

    protected $casts = [
        'redirect_uris' => 'array',
        'grant_types' => 'array',
        'scopes' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authorizationCodes(): HasMany
    {
        return $this->hasMany(OAuthAuthorizationCode::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(OAuthAccessToken::class);
    }
}
