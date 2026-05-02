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
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property int $sprint_number
 * @property string $name
 * @property string|null $goal
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int|null $capacity
 * @property string $status
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string $identifier
 * @property-read Project $project
 * @property-read Collection<int, SprintItem> $items
 */
class Sprint extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'scrum_sprints';

    protected $fillable = [
        'tenant_id', 'project_id', 'name', 'goal',
        'start_date', 'end_date', 'capacity', 'status',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'capacity' => 'integer',
        'sprint_number' => 'integer',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Sprint $sprint) {
            if (empty($sprint->status)) {
                $sprint->status = (string) config('core.default_sprint_status', 'planned');
            }

            if (empty($sprint->sprint_number)) {
                $sprint->sprint_number = static::nextSprintNumber($sprint->project_id);
            }
        });
    }

    /**
     * Generate the next sprint_number for a project, concurrent-safe.
     *
     * Wraps a SELECT max(sprint_number) ... FOR UPDATE inside a transaction
     * to serialize concurrent inserts in the same project.
     */
    protected static function nextSprintNumber(string $projectId): int
    {
        return DB::transaction(function () use ($projectId) {
            $max = static::withoutGlobalScope('tenant')
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->max('sprint_number');

            return ((int) ($max ?? 0)) + 1;
        });
    }

    public function getIdentifierAttribute(): string
    {
        $code = $this->project()->withoutGlobalScope('tenant')->value('code');

        return $code.'-S'.$this->sprint_number;
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<SprintItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SprintItem::class)->orderBy('position');
    }

    /** @return array<string, mixed> */
    public function format(): array
    {
        return [
            'id' => $this->id,
            'identifier' => $this->identifier,
            'project_code' => $this->project()->withoutGlobalScope('tenant')->value('code'),
            'name' => $this->name,
            'goal' => $this->goal,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'capacity' => $this->capacity,
            'status' => $this->status,
            'items_count' => $this->items_count ?? null,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
