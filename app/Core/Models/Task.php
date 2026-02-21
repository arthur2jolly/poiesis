<?php

namespace App\Core\Models;

use App\Core\Models\Concerns\HasArtifactIdentifier;
use App\Core\Models\Concerns\HasDependencies;
use App\Core\Models\Concerns\HasStatusTransitions;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $story_id
 * @property string $titre
 * @property string|null $description
 * @property string $type
 * @property string|null $nature
 * @property string $statut
 * @property string $priorite
 * @property int|null $ordre
 * @property int|null $estimation_temps
 * @property array<int, string>|null $tags
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Core\Models\Project $project
 * @property-read \App\Core\Models\Story|null $story
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasArtifactIdentifier, HasDependencies, HasFactory, HasStatusTransitions, HasUuids;

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }

    protected $fillable = [
        'project_id', 'story_id', 'titre', 'description', 'type',
        'nature', 'statut', 'priorite', 'ordre', 'estimation_temps', 'tags',
    ];

    protected $casts = [
        'tags' => 'array',
        'ordre' => 'integer',
        'estimation_temps' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function isStandalone(): bool
    {
        return $this->story_id === null;
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Task>
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['nature'] ?? null, fn ($q, $v) => $q->where('nature', $v))
            ->when($filters['statut'] ?? null, fn ($q, $v) => $q->where('statut', $v))
            ->when($filters['priorite'] ?? null, fn ($q, $v) => $q->where('priorite', $v))
            ->when($filters['tags'] ?? null, function ($q, $tags) {
                $tagList = is_array($tags) ? $tags : explode(',', $tags);
                foreach ($tagList as $tag) {
                    $q->whereJsonContains('tags', trim($tag));
                }
            })
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('titre', 'like', "%{$v}%")
                    ->orWhere('description', 'like', "%{$v}%");
            }));
    }

    public function format(): array
    {
        return [
            'identifier' => $this->identifier,
            'titre' => $this->titre,
            'description' => $this->description,
            'type' => $this->type,
            'nature' => $this->nature,
            'statut' => $this->statut,
            'priorite' => $this->priorite,
            'ordre' => $this->ordre,
            'estimation_temps' => $this->estimation_temps,
            'tags' => $this->tags,
            'story_identifier' => $this->story?->identifier,
            'standalone' => $this->isStandalone(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
