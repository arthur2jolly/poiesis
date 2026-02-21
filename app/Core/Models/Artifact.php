<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $project_id
 * @property string $identifier
 * @property int $sequence_number
 * @property string $artifactable_id
 * @property string $artifactable_type
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Core\Models\Project $project
 * @property-read \Illuminate\Database\Eloquent\Model $artifactable
 */
class Artifact extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'identifier', 'sequence_number',
        'artifactable_id', 'artifactable_type',
    ];

    protected $casts = [
        'sequence_number' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function artifactable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function resolveIdentifier(string $identifier): ?Model
    {
        $artifact = static::where('identifier', $identifier)
            ->with('artifactable')
            ->first();

        return $artifact?->artifactable;
    }

    /**
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public static function searchInProject(Project $project, string $keyword): Collection
    {
        $like = "%{$keyword}%";

        /** @var Collection<int, \Illuminate\Database\Eloquent\Model> $epics */
        $epics = Epic::where('project_id', $project->id)
            ->where(fn ($q) => $q->where('titre', 'like', $like)->orWhere('description', 'like', $like))
            ->get();

        /** @var Collection<int, \Illuminate\Database\Eloquent\Model> $stories */
        $stories = Story::whereHas('epic', fn ($q) => $q->where('project_id', $project->id))
            ->where(fn ($q) => $q->where('titre', 'like', $like)->orWhere('description', 'like', $like))
            ->get();

        /** @var Collection<int, \Illuminate\Database\Eloquent\Model> $tasks */
        $tasks = Task::where('project_id', $project->id)
            ->where(fn ($q) => $q->where('titre', 'like', $like)->orWhere('description', 'like', $like))
            ->get();

        return $epics->merge($stories)->merge($tasks);
    }
}
