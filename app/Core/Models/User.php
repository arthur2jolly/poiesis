<?php

namespace App\Core\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * @property string $id
 * @property string $name
 * @property string|null $password
 * @property int $role
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\Project> $projects
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\ApiToken> $apiTokens
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\OAuthClient> $oauthClients
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function setPasswordAttribute(?string $value): void
    {
        if ($value === null || empty($value)) {
            throw new \InvalidArgumentException('Password cannot be empty.');
        }
        $this->attributes['password'] = Hash::make($value);
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            if (empty($user->password)) {
                throw new \InvalidArgumentException('Password is required when creating a user.');
            }
            if ($user->role === null) {
                $user->role = 4; // Default to Viewer
            }
        });
    }

    public function isAdministrator(): bool
    {
        return $this->role === 1;
    }

    public function isManager(): bool
    {
        return $this->role === 2;
    }

    public function isDeveloper(): bool
    {
        return $this->role === 3;
    }

    public function isViewer(): bool
    {
        return $this->role === 4;
    }

    public function getRoleName(): string
    {
        return config('core.user_roles')[$this->role] ?? 'Unknown';
    }

    protected $fillable = ['name', 'password', 'role'];

    protected $hidden = ['password'];

    protected $casts = [
        'role' => 'integer',
    ];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->using(ProjectMember::class)
            ->withPivot('role', 'created_at');
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function oauthClients(): HasMany
    {
        return $this->hasMany(OAuthClient::class);
    }
}
