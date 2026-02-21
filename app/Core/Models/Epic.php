<?php

namespace App\Core\Models;

use App\Core\Models\Concerns\HasArtifactIdentifier;
use Database\Factories\EpicFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property string $titre
 * @property string|null $description
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Core\Models\Project $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\Story> $stories
 */
class Epic extends Model
{
    /** @use HasFactory<EpicFactory> */
    use HasArtifactIdentifier, HasFactory, HasUuids;

    protected static function newFactory(): EpicFactory
    {
        return EpicFactory::new();
    }

    protected $fillable = ['project_id', 'titre', 'description'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    public function getProjectCodeAttribute(): string
    {
        return $this->project->code;
    }

    public function format(): array
    {
        return [
            'identifier' => $this->identifier,
            'titre' => $this->titre,
            'description' => $this->description,
            'stories_count' => $this->stories_count ?? $this->stories()->count(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
