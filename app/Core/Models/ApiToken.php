<?php

namespace App\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $token
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $last_used_at
 * @property \Carbon\Carbon $created_at
 * @property-read \App\Core\Models\User $user
 */
class ApiToken extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'name', 'token', 'expires_at'];

    public const UPDATED_AT = null;

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function recordUsage(): void
    {
        $this->last_used_at = Carbon::now();
        $this->saveQuietly();
    }

    /**
     * @return array{raw: string, hash: string}
     */
    public static function generateRaw(): array
    {
        $raw = 'aa-'.bin2hex(random_bytes(20));

        return [
            'raw' => $raw,
            'hash' => hash('sha256', $raw),
        ];
    }
}
