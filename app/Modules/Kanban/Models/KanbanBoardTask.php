<?php

declare(strict_types=1);

namespace App\Modules\Kanban\Models;

use App\Core\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $column_id
 * @property string $task_id
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read KanbanColumn $column
 * @property-read Task $task
 */
class KanbanBoardTask extends Model
{
    use HasUuids;

    protected $table = 'kanban_board_task';

    protected $fillable = ['column_id', 'task_id', 'position'];

    protected $casts = [
        'position' => 'integer',
    ];

    public function column(): BelongsTo
    {
        return $this->belongsTo(KanbanColumn::class, 'column_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return array<string, mixed> */
    public function format(): array
    {
        return [
            'id' => $this->id,
            'column_id' => $this->column_id,
            'column_name' => $this->column->name,
            'task' => $this->task->format(),
            'position' => $this->position,
        ];
    }
}
