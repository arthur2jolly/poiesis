<?php

use App\Core\Http\Controllers\ArtifactController;
use App\Core\Http\Controllers\ConfigController;
use App\Core\Http\Controllers\DependencyController;
use App\Core\Http\Controllers\EpicController;
use App\Core\Http\Controllers\ModuleController;
use App\Core\Http\Controllers\ProjectController;
use App\Core\Http\Controllers\ProjectMemberController;
use App\Core\Http\Controllers\StoryController;
use App\Core\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {

    // Public endpoint — no auth required
    Route::get('/config', [ConfigController::class, 'index']);

    Route::middleware('auth.bearer')->group(function (): void {
        // Health-check
        Route::get('/ping', fn () => response()->json(['status' => 'ok']));

        // Available modules (global)
        Route::get('/modules', [ModuleController::class, 'available']);

        // Projects
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);

        // Artifacts (resolve by identifier — no project scoping needed)
        Route::get('/artifacts/{identifier}', [ArtifactController::class, 'resolve']);

        // Dependencies (cross-project identifiers)
        Route::post('/dependencies', [DependencyController::class, 'store']);
        Route::delete('/dependencies', [DependencyController::class, 'destroy']);
        Route::get('/artifacts/{identifier}/dependencies', [DependencyController::class, 'show']);

        // Project-scoped routes
        Route::middleware('project.access')->group(function (): void {
            // Legacy test routes (used by E4 auth tests)
            Route::get('/projects/{code}/access-check', fn () => response()->json(['access' => 'granted']));
            Route::get('/projects/{code}/module-check/sprint', fn () => response()->json(['module' => 'sprint']))
                ->middleware('module.active:sprint');
        });

        Route::middleware('project.access')->prefix('projects/{code}')->group(function (): void {

            // Project CRUD
            Route::get('/', [ProjectController::class, 'show']);
            Route::patch('/', [ProjectController::class, 'update']);
            Route::delete('/', [ProjectController::class, 'destroy']);

            // Members
            Route::get('/members', [ProjectMemberController::class, 'index']);
            Route::post('/members', [ProjectMemberController::class, 'store']);
            Route::patch('/members/{memberId}', [ProjectMemberController::class, 'update']);
            Route::delete('/members/{memberId}', [ProjectMemberController::class, 'destroy']);

            // Modules (project-scoped)
            Route::get('/modules', [ModuleController::class, 'active']);
            Route::post('/modules', [ModuleController::class, 'activate']);
            Route::delete('/modules/{slug}', [ModuleController::class, 'deactivate']);

            // Artifacts search
            Route::get('/artifacts', [ArtifactController::class, 'search']);

            // Epics
            Route::get('/epics', [EpicController::class, 'index']);
            Route::post('/epics', [EpicController::class, 'store']);
            Route::get('/epics/{identifier}', [EpicController::class, 'show']);
            Route::patch('/epics/{identifier}', [EpicController::class, 'update']);
            Route::delete('/epics/{identifier}', [EpicController::class, 'destroy']);

            // Stories (project-wide)
            Route::get('/stories', [StoryController::class, 'index']);
            Route::get('/stories/{identifier}', [StoryController::class, 'show']);
            Route::patch('/stories/{identifier}', [StoryController::class, 'update']);
            Route::delete('/stories/{identifier}', [StoryController::class, 'destroy']);
            Route::patch('/stories/{identifier}/status', [StoryController::class, 'transition']);

            // Stories (nested under epic)
            Route::get('/epics/{identifier}/stories', [StoryController::class, 'indexByEpic']);
            Route::post('/epics/{identifier}/stories', [StoryController::class, 'store']);
            Route::post('/epics/{identifier}/stories/batch', [StoryController::class, 'batchStore']);

            // Tasks (project-wide)
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::post('/tasks', [TaskController::class, 'storeStandalone']);
            Route::post('/tasks/batch', [TaskController::class, 'batchStoreStandalone']);
            Route::get('/tasks/{identifier}', [TaskController::class, 'show']);
            Route::patch('/tasks/{identifier}', [TaskController::class, 'update']);
            Route::delete('/tasks/{identifier}', [TaskController::class, 'destroy']);
            Route::patch('/tasks/{identifier}/status', [TaskController::class, 'transition']);

            // Tasks (nested under story)
            Route::get('/stories/{identifier}/tasks', [TaskController::class, 'indexByStory']);
            Route::post('/stories/{identifier}/tasks', [TaskController::class, 'storeChild']);
            Route::post('/stories/{identifier}/tasks/batch', [TaskController::class, 'batchStoreChild']);
        });
    });
});
