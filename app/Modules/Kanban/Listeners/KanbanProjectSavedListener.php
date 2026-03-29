<?php

declare(strict_types=1);

namespace App\Modules\Kanban\Listeners;

use App\Core\Models\Project;
use App\Modules\Kanban\Models\KanbanBoard;
use App\Modules\Kanban\Models\KanbanColumn;

class KanbanProjectSavedListener
{
    public function handle(Project $project): void
    {
        $oldModules = $project->getOriginal('modules') ?? [];
        $newModules = $project->modules ?? [];

        if (in_array('kanban', $newModules, true) && ! in_array('kanban', $oldModules, true)) {
            $this->createDefaultBoard($project);
        }
    }

    private function createDefaultBoard(Project $project): void
    {
        $board = KanbanBoard::create([
            'project_id' => $project->id,
            'name' => 'Kanban board',
        ]);

        $columns = [
            ['name' => 'To Do', 'position' => 0],
            ['name' => 'WIP', 'position' => 1],
            ['name' => 'Done', 'position' => 2],
        ];

        foreach ($columns as $col) {
            KanbanColumn::create([
                'board_id' => $board->id,
                'name' => $col['name'],
                'position' => $col['position'],
            ]);
        }
    }
}
