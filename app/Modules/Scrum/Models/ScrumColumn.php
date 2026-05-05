<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Models;

use App\Core\Models\Concerns\BelongsToTenant;
use App\Core\Models\Project;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $name
 * @property int $position
 * @property int|null $limit_warning
 * @property int|null $limit_hard
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int|null $placements_count
 * @property-read Project $project
 * @property-read Collection<int, ScrumItemPlacement> $placements
 */
class ScrumColumn extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'scrum_columns';

    protected $fillable = [
        'tenant_id', 'project_id', 'name', 'position', 'limit_warning', 'limit_hard',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'position' => 'integer',
        'limit_warning' => 'integer',
        'limit_hard' => 'integer',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ScrumItemPlacement, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(ScrumItemPlacement::class, 'column_id')->orderBy('position');
    }

    public function placementCount(): int
    {
        return $this->placements()->count();
    }

    public function isAtWarningLimit(): bool
    {
        return $this->limit_warning !== null && $this->placementCount() >= $this->limit_warning;
    }

    public function isAtHardLimit(): bool
    {
        return $this->limit_hard !== null && $this->placementCount() >= $this->limit_hard;
    }

    /** @return array<string, mixed> */
    public function format(): array
    {
        $count = $this->placements_count ?? $this->placementCount();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'limit_warning' => $this->limit_warning,
            'limit_hard' => $this->limit_hard,
            'placement_count' => $count,
            'at_warning' => $this->limit_warning !== null && $count >= $this->limit_warning,
            'at_hard_limit' => $this->limit_hard !== null && $count >= $this->limit_hard,
        ];
    }
}
