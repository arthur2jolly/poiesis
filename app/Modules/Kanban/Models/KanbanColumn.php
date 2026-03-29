<?php

declare(strict_types=1);

namespace App\Modules\Kanban\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $board_id
 * @property string $name
 * @property int $position
 * @property int|null $limit_warning
 * @property int|null $limit_hard
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read KanbanBoard $board
 * @property-read Collection<int, KanbanBoardTask> $boardTasks
 */
class KanbanColumn extends Model
{
    use HasUuids;

    protected $table = 'kanban_columns';

    protected $fillable = ['board_id', 'name', 'position', 'limit_warning', 'limit_hard'];

    protected $casts = [
        'position' => 'integer',
        'limit_warning' => 'integer',
        'limit_hard' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(KanbanBoard::class, 'board_id');
    }

    public function boardTasks(): HasMany
    {
        return $this->hasMany(KanbanBoardTask::class, 'column_id')->orderBy('position');
    }

    public function taskCount(): int
    {
        return $this->boardTasks()->count();
    }

    public function isAtHardLimit(): bool
    {
        return $this->limit_hard !== null && $this->taskCount() >= $this->limit_hard;
    }

    public function isAtWarningLimit(): bool
    {
        return $this->limit_warning !== null && $this->taskCount() >= $this->limit_warning;
    }

    /** @return array<string, mixed> */
    public function format(): array
    {
        $count = $this->board_tasks_count ?? $this->taskCount();

        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'position' => $this->position,
            'limit_warning' => $this->limit_warning,
            'limit_hard' => $this->limit_hard,
            'task_count' => $count,
            'at_warning' => $this->limit_warning !== null && $count >= $this->limit_warning,
            'at_hard_limit' => $this->limit_hard !== null && $count >= $this->limit_hard,
        ];
    }
}
