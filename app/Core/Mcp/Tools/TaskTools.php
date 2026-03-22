<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Artifact;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Services\DependencyService;
use App\Core\Support\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskTools implements McpToolInterface
{
    /**
     * Returns the list of tool definitions provided by this class.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return [
            $this->getListTasksDescription(),
            $this->getListStoryTasksDescription(),
            $this->getGetTaskDescription(),
            $this->getCreateTaskDescription(),
            $this->getCreateTasksDescription(),
            $this->getUpdateTaskDescription(),
            $this->getDeleteTaskDescription(),
            $this->getUpdateTaskStatusDescription(),
        ];
    }

    /**
     * Dispatches execution to the appropriate private method.
     *
     * @param  string  $toolName  Name of the tool to execute.
     * @param  array<string, mixed>  $params  Input arguments provided by the caller.
     * @param  User  $user  Authenticated user performing the action.
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the tool name is not handled by this class.
     * @throws ValidationException On invalid input or access denial.
     * @throws ModelNotFoundException When the project does not exist.
     */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'list_tasks' => $this->listTasks($params, $user),
            'list_story_tasks' => $this->listStoryTasks($params, $user),
            'get_task' => $this->getTask($params, $user),
            'create_task' => $this->createTask($params, $user),
            'create_tasks' => $this->createTasks($params, $user),
            'update_task' => $this->updateTask($params, $user),
            'delete_task' => $this->deleteTask($params, $user),
            'update_task_status' => $this->updateTaskStatus($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    private function getListTasksDescription(): array
    {
        return [
            'name' => 'list_tasks',
            'description' => 'List all tasks in a project (standalone + children) with optional filters',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'nature' => ['type' => 'string'],
                    'statut' => ['type' => 'string'],
                    'priorite' => ['type' => 'string'],
                    'tags' => ['type' => 'string'],
                    'q' => ['type' => 'string'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    private function getListStoryTasksDescription(): array
    {
        return [
            'name' => 'list_story_tasks',
            'description' => 'List tasks of a specific story',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'story_identifier' => ['type' => 'string'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ],
                'required' => ['project_code', 'story_identifier'],
            ],
        ];
    }

    private function getGetTaskDescription(): array
    {
        return [
            'name' => 'get_task',
            'description' => 'Get task details by its identifier',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function getCreateTaskDescription(): array
    {
        return [
            'name' => 'create_task',
            'description' => 'Create a task (standalone or child of a story)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'story_identifier' => ['type' => 'string', 'description' => 'If provided, creates a child task'],
                    'titre' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'nature' => ['type' => 'string'],
                    'priorite' => ['type' => 'string'],
                    'ordre' => ['type' => 'integer'],
                    'estimation_temps' => ['type' => 'integer', 'description' => 'Time estimate in minutes'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['project_code', 'titre', 'type'],
            ],
        ];
    }

    private function getCreateTasksDescription(): array
    {
        return [
            'name' => 'create_tasks',
            'description' => 'Create multiple tasks in one operation (atomic)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'story_identifier' => ['type' => 'string', 'description' => 'If provided, all tasks are children of this story'],
                    'tasks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'titre' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'nature' => ['type' => 'string'],
                                'priorite' => ['type' => 'string'],
                                'ordre' => ['type' => 'integer'],
                                'estimation_temps' => ['type' => 'integer'],
                                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['titre', 'type'],
                        ],
                    ],
                ],
                'required' => ['project_code', 'tasks'],
            ],
        ];
    }

    private function getUpdateTaskDescription(): array
    {
        return [
            'name' => 'update_task',
            'description' => 'Update a task',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'titre' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'nature' => ['type' => 'string'],
                    'priorite' => ['type' => 'string'],
                    'ordre' => ['type' => 'integer'],
                    'estimation_temps' => ['type' => 'integer'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function getDeleteTaskDescription(): array
    {
        return [
            'name' => 'delete_task',
            'description' => 'Delete a task',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function getUpdateTaskStatusDescription(): array
    {
        return [
            'name' => 'update_task_status',
            'description' => 'Change the status of a task (draft->open->closed, closed->open)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'statut' => ['type' => 'string'],
                ],
                'required' => ['identifier', 'statut'],
            ],
        ];
    }

    private function listTasks(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $perPage = min((int) ($params['per_page'] ?? 25), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $tasks = $project->tasks()->filter($params)->with('artifact')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $tasks->map(fn (Task $t) => $t->format())->all(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
                'last_page' => $tasks->lastPage(),
            ],
        ];
    }

    private function listStoryTasks(array $params, User $user): array
    {
        $this->findProjectWithAccess($params['project_code'], $user);
        $story = $this->resolveStory($params['story_identifier']);

        $perPage = min((int) ($params['per_page'] ?? 25), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $tasks = $story->tasks()->with('artifact')->orderBy('ordre')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $tasks->map(fn (Task $t) => $t->format())->all(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
                'last_page' => $tasks->lastPage(),
            ],
        ];
    }

    private function getTask(array $params, User $user): array
    {
        $task = $this->resolveTask($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $task->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $deps = app(DependencyService::class)->getDependencies($task);

        $result = $task->format();
        $result['blocked_by'] = array_map(fn ($m) => $m->identifier, $deps['blocked_by']);
        $result['blocks'] = array_map(fn ($m) => $m->identifier, $deps['blocks']);

        return $result;
    }

    private function createTask(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'task' => ['You do not have permission to create tasks.'],
            ]);
        }

        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $this->validateTaskData($params);

        $storyId = null;
        if (! empty($params['story_identifier'])) {
            $story = $this->resolveStory($params['story_identifier']);
            $storyId = $story->id;
        }

        $task = Task::create([
            'project_id' => $project->id,
            'story_id' => $storyId,
            'titre' => $params['titre'],
            'type' => $params['type'],
            'description' => $params['description'] ?? null,
            'nature' => $params['nature'] ?? null,
            'priorite' => $params['priorite'] ?? config('core.default_priority'),
            'statut' => config('core.default_statut'),
            'ordre' => $params['ordre'] ?? null,
            'estimation_temps' => $params['estimation_temps'] ?? null,
            'tags' => $params['tags'] ?? null,
        ]);

        $task->load('artifact');

        return $task->format();
    }

    private function createTasks(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'tasks' => ['You do not have permission to create tasks.'],
            ]);
        }

        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $tasksData = $params['tasks'] ?? [];

        $storyId = null;
        if (! empty($params['story_identifier'])) {
            $story = $this->resolveStory($params['story_identifier']);
            $storyId = $story->id;
        }

        foreach ($tasksData as $index => $taskData) {
            try {
                $this->validateTaskData($taskData);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    "tasks.{$index}" => $e->errors(),
                ]);
            }
        }

        $created = DB::transaction(function () use ($project, $storyId, $tasksData) {
            $results = [];
            foreach ($tasksData as $data) {
                $task = Task::create([
                    'project_id' => $project->id,
                    'story_id' => $storyId,
                    'titre' => $data['titre'],
                    'type' => $data['type'],
                    'description' => $data['description'] ?? null,
                    'nature' => $data['nature'] ?? null,
                    'priorite' => $data['priorite'] ?? config('core.default_priority'),
                    'statut' => config('core.default_statut'),
                    'ordre' => $data['ordre'] ?? null,
                    'estimation_temps' => $data['estimation_temps'] ?? null,
                    'tags' => $data['tags'] ?? null,
                ]);
                $task->load('artifact');
                $results[] = $task->format();
            }

            return $results;
        });

        return ['data' => $created];
    }

    private function updateTask(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'task' => ['You do not have permission to update tasks.'],
            ]);
        }

        $task = $this->resolveTask($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $task->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $updatable = ['titre', 'description', 'type', 'nature', 'priorite', 'ordre', 'estimation_temps', 'tags'];
        $data = [];
        foreach ($updatable as $field) {
            if (array_key_exists($field, $params)) {
                $data[$field] = $params[$field];
            }
        }

        if (isset($data['type']) && ! in_array($data['type'], config('core.item_types'), true)) {
            throw ValidationException::withMessages(['type' => ['Invalid type.']]);
        }
        if (isset($data['nature']) && $data['nature'] !== null && ! in_array($data['nature'], config('core.work_natures'), true)) {
            throw ValidationException::withMessages(['nature' => ['Invalid nature.']]);
        }
        if (isset($data['priorite']) && ! in_array($data['priorite'], config('core.priorities'), true)) {
            throw ValidationException::withMessages(['priorite' => ['Invalid priority.']]);
        }

        if (! empty($data)) {
            $task->update($data);
        }

        return $task->format();
    }

    private function deleteTask(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'task' => ['You do not have permission to delete tasks.'],
            ]);
        }

        $task = $this->resolveTask($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $task->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $task->delete();

        return ['message' => 'Task deleted.'];
    }

    private function updateTaskStatus(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'task' => ['You do not have permission to update tasks.'],
            ]);
        }

        $task = $this->resolveTask($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $task->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $task->transitionStatus($params['statut']);

        return $task->format();
    }

    private function validateTaskData(array $data): void
    {
        if (empty($data['titre'])) {
            throw ValidationException::withMessages(['titre' => ['The titre field is required.']]);
        }
        if (empty($data['type']) || ! in_array($data['type'], config('core.item_types'), true)) {
            throw ValidationException::withMessages(['type' => ['The selected type is invalid.']]);
        }
        if (! empty($data['nature']) && ! in_array($data['nature'], config('core.work_natures'), true)) {
            throw ValidationException::withMessages(['nature' => ['The selected nature is invalid.']]);
        }
        if (! empty($data['priorite']) && ! in_array($data['priorite'], config('core.priorities'), true)) {
            throw ValidationException::withMessages(['priorite' => ['The selected priority is invalid.']]);
        }
    }

    private function resolveTask(string $identifier): Task
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Task) {
            throw ValidationException::withMessages([
                'identifier' => ["'{$identifier}' is not a task."],
            ]);
        }

        return $model;
    }

    private function resolveStory(string $identifier): Story
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Story) {
            throw ValidationException::withMessages([
                'identifier' => ["'{$identifier}' is not a story."],
            ]);
        }

        return $model;
    }

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        if (! ProjectMember::where('project_id', $project->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        return $project;
    }
}
