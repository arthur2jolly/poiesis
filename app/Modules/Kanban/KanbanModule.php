<?php

declare(strict_types=1);

namespace App\Modules\Kanban;

use App\Core\Contracts\ModuleInterface;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Project;
use App\Core\Models\Task;
use App\Modules\Kanban\Listeners\KanbanProjectSavedListener;
use App\Modules\Kanban\Listeners\KanbanTaskObserver;
use App\Modules\Kanban\Mcp\KanbanTools;

class KanbanModule implements ModuleInterface
{
    public function slug(): string
    {
        return 'kanban';
    }

    public function name(): string
    {
        return 'Kanban';
    }

    public function description(): string
    {
        return 'Visual flow management with boards, customizable columns, and WIP limits for standalone tasks';
    }

    /** @return array<int, string> */
    public function dependencies(): array
    {
        return [];
    }

    public function registerRoutes(): void {}

    public function registerListeners(): void
    {
        Project::saved(function (Project $project) {
            (new KanbanProjectSavedListener)->handle($project);
        });
        Task::observe(KanbanTaskObserver::class);
    }

    public function migrationPath(): string
    {
        return __DIR__.'/Database/Migrations';
    }

    /** @return array<int, McpToolInterface> */
    public function mcpTools(): array
    {
        return [new KanbanTools];
    }
}
