<?php

declare(strict_types=1);

namespace App\Modules\Scrum;

use App\Core\Contracts\ModuleInterface;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Modules\Scrum\Http\Controllers\ScrumController;
use App\Modules\Scrum\Http\Middleware\AuthenticateScrumWeb;
use App\Modules\Scrum\Mcp\ScrumTools;
use Illuminate\Support\Facades\Route;

class ScrumModule implements ModuleInterface
{
    public function slug(): string
    {
        return 'scrum';
    }

    public function name(): string
    {
        return 'Scrum';
    }

    public function description(): string
    {
        return 'Sprint planning, backlog management and capacity tracking for Scrum-style iterations';
    }

    /** @return array<int, string> */
    public function dependencies(): array
    {
        return [];
    }

    public function registerRoutes(): void
    {
        view()->addNamespace('scrum', __DIR__.'/Resources/views');

        Route::middleware(['web', AuthenticateScrumWeb::class, 'project.access', 'module.active:scrum'])
            ->prefix('scrum/{code}')
            ->name('scrum.')
            ->group(function (): void {
                Route::get('/sprints', [ScrumController::class, 'sprints'])->name('sprints');
                Route::get('/sprints/{identifier}', [ScrumController::class, 'sprint'])->name('sprint');
                Route::get('/backlog', [ScrumController::class, 'backlog'])->name('backlog');
                Route::get('/board', [ScrumController::class, 'activeBoard'])->name('board');
                Route::get('/board/{sprint_identifier}', [ScrumController::class, 'board'])->name('board.sprint');
            });
    }

    public function registerListeners(): void {}

    public function migrationPath(): string
    {
        return __DIR__.'/Database/Migrations';
    }

    /** @return array<int, McpToolInterface> */
    public function mcpTools(): array
    {
        return [new ScrumTools];
    }
}
