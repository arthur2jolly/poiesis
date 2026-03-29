<?php

declare(strict_types=1);

namespace App\Modules\Kanban\Listeners;

use App\Core\Models\Task;
use App\Modules\Kanban\Models\KanbanBoardTask;

class KanbanTaskObserver
{
    public function updated(Task $task): void
    {
        // RM-13: task closed → remove from board
        if ($task->isDirty('statut') && $task->statut === 'closed') {
            $this->removeFromBoard($task);
        }

        // RM-15: task attached to a story → remove from board
        if ($task->isDirty('story_id') && $task->story_id !== null && $task->getOriginal('story_id') === null) {
            $this->removeFromBoard($task);
        }
    }

    private function removeFromBoard(Task $task): void
    {
        $boardTask = KanbanBoardTask::where('task_id', $task->id)->first();

        if ($boardTask === null) {
            return;
        }

        $columnId = $boardTask->column_id;
        $boardTask->delete();

        $this->recompactPositions($columnId);
    }

    private function recompactPositions(string $columnId): void
    {
        $entries = KanbanBoardTask::where('column_id', $columnId)
            ->orderBy('position')
            ->get();

        foreach ($entries as $index => $entry) {
            if ($entry->position !== $index) {
                $entry->update(['position' => $index]);
            }
        }
    }
}
