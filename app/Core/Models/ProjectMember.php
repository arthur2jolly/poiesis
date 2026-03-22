<?php

namespace App\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $id
 * @property string $project_id
 * @property string $user_id
 * @property string $position
 * @property Carbon|null $created_at
 * @property-read Project $project
 * @property-read User $user
 */
class ProjectMember extends Pivot
{
    use HasUuids;

    protected $table = 'project_members';

    public $incrementing = false;

    protected $fillable = ['project_id', 'user_id', 'position'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public const UPDATED_AT = null;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function isLastOwner(string $projectId, string $userId): bool
    {
        $ownerCount = static::where('project_id', $projectId)
            ->where('position', 'owner')
            ->count();

        if ($ownerCount > 1) {
            return false;
        }

        return static::where('project_id', $projectId)
            ->where('user_id', $userId)
            ->where('position', 'owner')
            ->exists();
    }
}
