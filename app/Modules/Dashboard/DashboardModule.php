<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Contracts\ModuleInterface;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Modules\Dashboard\Http\Controllers\AuthController;
use App\Modules\Dashboard\Http\Controllers\DashboardController;
use App\Modules\Dashboard\Http\Middleware\AuthenticateWeb;
use Illuminate\Support\Facades\Route;

class DashboardModule implements ModuleInterface
{
    public function slug(): string
    {
        return 'dashboard';
    }

    public function name(): string
    {
        return 'Dashboard';
    }

    public function description(): string
    {
        return 'Read-only web interface for visualizing projects, epics, stories and tasks';
    }

    /** @return array<int, string> */
    public function dependencies(): array
    {
        return [];
    }

    public function registerRoutes(): void
    {
        view()->addNamespace('dashboard', __DIR__.'/Resources/views');

        Route::middleware('web')->group(function (): void {
            Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
            Route::post('/login', [AuthController::class, 'login']);
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

            Route::middleware(AuthenticateWeb::class)
                ->prefix('dashboard')
                ->name('dashboard.')
                ->group(function (): void {
                    Route::get('/', [DashboardController::class, 'projects'])->name('projects');
                    Route::get('/{code}', [DashboardController::class, 'projectOverview'])->name('project');
                    Route::get('/{code}/epics', [DashboardController::class, 'epics'])->name('epics');
                    Route::get('/{code}/epics/{identifier}', [DashboardController::class, 'epic'])->name('epic');
                    Route::get('/{code}/stories', [DashboardController::class, 'stories'])->name('stories');
                    Route::get('/{code}/stories/{identifier}', [DashboardController::class, 'story'])->name('story');
                    Route::get('/{code}/tasks', [DashboardController::class, 'tasks'])->name('tasks');
                    Route::get('/{code}/tasks/{identifier}', [DashboardController::class, 'task'])->name('task');
                });
        });
    }

    public function registerListeners(): void {}

    public function migrationPath(): string
    {
        return '';
    }

    /** @return array<int, McpToolInterface> */
    public function mcpTools(): array
    {
        return [];
    }
}
