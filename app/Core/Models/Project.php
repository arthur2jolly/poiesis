<?php

namespace App\Core\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $code
 * @property string $titre
 * @property string|null $description
 * @property array<int, string> $modules
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\Epic> $epics
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\Task> $tasks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\Task> $standaloneTasks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\Artifact> $artifacts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\User> $users
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Core\Models\ProjectMember> $members
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids;

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    protected $fillable = ['code', 'titre', 'description', 'modules'];

    protected $casts = [
        'modules' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function epics(): HasMany
    {
        return $this->hasMany(Epic::class);
    }

    public function standaloneTasks(): HasMany
    {
        return $this->hasMany(Task::class)->whereNull('story_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->using(ProjectMember::class)
            ->withPivot('role', 'created_at');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeAccessibleBy(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
    }

    public function format(): array
    {
        return [
            'code' => $this->code,
            'titre' => $this->titre,
            'description' => $this->description,
            'modules' => $this->modules,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
