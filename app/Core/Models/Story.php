<?php

namespace App\Core\Models;

use App\Core\Models\Concerns\HasArtifactIdentifier;
use App\Core\Models\Concerns\HasDependencies;
use App\Core\Models\Concerns\HasStatusTransitions;
use Carbon\Carbon;
use Database\Factories\StoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $epic_id
 * @property string $titre
 * @property string|null $description
 * @property string $type
 * @property string|null $nature
 * @property string $statut
 * @property string $priorite
 * @property int|null $ordre
 * @property int|null $story_points
 * @property int|null $rank
 * @property string|null $reference_doc
 * @property array<int, string>|null $tags
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Epic $epic
 * @property-read Collection<int, Task> $tasks
 */
class Story extends Model
{
    /** @use HasFactory<StoryFactory> */
    use HasArtifactIdentifier, HasDependencies, HasFactory, HasStatusTransitions, HasUuids;

    protected static function newFactory(): StoryFactory
    {
        return StoryFactory::new();
    }

    protected $fillable = [
        'epic_id', 'titre', 'description', 'type', 'nature',
        'statut', 'priorite', 'ordre', 'story_points',
        'reference_doc', 'tags', 'rank',
    ];

    protected $casts = [
        'tags' => 'array',
        'ordre' => 'integer',
        'story_points' => 'integer',
        'rank' => 'integer',
    ];

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    protected function getProjectIdForArtifact(): string
    {
        return $this->epic->project_id;
    }

    protected function getProjectCodeForArtifact(): string
    {
        return $this->epic->project->code;
    }

    /**
     * @param  Builder<Story>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Story>
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
            'story_points' => $this->story_points,
            'reference_doc' => $this->reference_doc,
            'tags' => $this->tags,
            'tasks_count' => $this->tasks_count ?? $this->tasks()->count(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
