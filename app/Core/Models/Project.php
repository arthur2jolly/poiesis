<?php

namespace App\Core\Models;

use App\Core\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $titre
 * @property string|null $description
 * @property array<int, string> $modules
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Epic> $epics
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, Task> $standaloneTasks
 * @property-read Collection<int, Artifact> $artifacts
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, ProjectMember> $members
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    protected $fillable = ['tenant_id', 'code', 'titre', 'description', 'modules'];

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
            ->withPivot('position', 'created_at');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
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
