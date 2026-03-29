<?php

declare(strict_types=1);

namespace App\Modules\Kanban\Mcp;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Artifact;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Support\Role;
use App\Modules\Kanban\Models\KanbanBoard;
use App\Modules\Kanban\Models\KanbanBoardTask;
use App\Modules\Kanban\Models\KanbanColumn;
use Illuminate\Validation\ValidationException;

class KanbanTools implements McpToolInterface
{
    /** @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function tools(): array
    {
        return [
            ['name' => 'kanban_board_create', 'description' => 'Create a Kanban board in a project', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'name' => ['type' => 'string', 'description' => 'Board name']], 'required' => ['project_code', 'name']]],
            ['name' => 'kanban_board_list', 'description' => 'List Kanban boards of a project', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string']], 'required' => ['project_code']]],
            ['name' => 'kanban_board_get', 'description' => 'Get a Kanban board with columns and tasks', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string', 'description' => 'Board UUID']], 'required' => ['project_code', 'board_id']]],
            ['name' => 'kanban_board_update', 'description' => 'Rename a Kanban board', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'name' => ['type' => 'string', 'description' => 'New board name']], 'required' => ['project_code', 'board_id', 'name']]],
            ['name' => 'kanban_board_delete', 'description' => 'Delete a Kanban board (must be empty)', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string']], 'required' => ['project_code', 'board_id']]],
            ['name' => 'kanban_column_create', 'description' => 'Add a column to a Kanban board', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'limit_warning' => ['type' => 'integer', 'description' => 'Warning threshold (optional)'], 'limit_hard' => ['type' => 'integer', 'description' => 'Hard limit (optional)']], 'required' => ['project_code', 'board_id', 'name']]],
            ['name' => 'kanban_column_list', 'description' => 'List columns of a Kanban board with task counts', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string']], 'required' => ['project_code', 'board_id']]],
            ['name' => 'kanban_column_update', 'description' => 'Update a column (name, limits)', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'column_id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'limit_warning' => ['type' => ['integer', 'null'], 'description' => 'Warning threshold (null to remove)'], 'limit_hard' => ['type' => ['integer', 'null'], 'description' => 'Hard limit (null to remove)']], 'required' => ['project_code', 'board_id', 'column_id']]],
            ['name' => 'kanban_column_delete', 'description' => 'Delete a column (must be empty)', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'column_id' => ['type' => 'string']], 'required' => ['project_code', 'board_id', 'column_id']]],
            ['name' => 'kanban_column_reorder', 'description' => 'Reorder all columns of a board', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'column_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ordered list of column UUIDs']], 'required' => ['project_code', 'board_id', 'column_ids']]],
            ['name' => 'kanban_task_add', 'description' => 'Add a standalone task to a Kanban board', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'task_identifier' => ['type' => 'string', 'description' => 'Task artifact identifier (e.g. PROJ-12)'], 'column_id' => ['type' => 'string', 'description' => 'Target column UUID (optional, defaults to first column)']], 'required' => ['project_code', 'board_id', 'task_identifier']]],
            ['name' => 'kanban_task_remove', 'description' => 'Remove a task from a Kanban board', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'task_identifier' => ['type' => 'string']], 'required' => ['project_code', 'board_id', 'task_identifier']]],
            ['name' => 'kanban_task_move', 'description' => 'Move a task to a target column', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'task_identifier' => ['type' => 'string'], 'column_id' => ['type' => 'string', 'description' => 'Target column UUID'], 'position' => ['type' => 'integer', 'description' => 'Target position (optional, defaults to last)']], 'required' => ['project_code', 'board_id', 'task_identifier', 'column_id']]],
            ['name' => 'kanban_task_reorder', 'description' => 'Reorder tasks within a column', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'column_id' => ['type' => 'string'], 'task_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ordered list of task UUIDs']], 'required' => ['project_code', 'board_id', 'column_id', 'task_ids']]],
            ['name' => 'kanban_task_list', 'description' => 'List tasks on a board or in a column', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'column_id' => ['type' => 'string', 'description' => 'Column UUID (optional)']], 'required' => ['project_code', 'board_id']]],
            ['name' => 'kanban_column_close_tasks', 'description' => 'Close all tasks in a column (bulk close)', 'inputSchema' => ['type' => 'object', 'properties' => ['project_code' => ['type' => 'string'], 'board_id' => ['type' => 'string'], 'column_id' => ['type' => 'string']], 'required' => ['project_code', 'board_id', 'column_id']]],
        ];
    }

    /** @param array<string, mixed> $params */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'kanban_board_create' => $this->boardCreate($params, $user),
            'kanban_board_list' => $this->boardList($params, $user),
            'kanban_board_get' => $this->boardGet($params, $user),
            'kanban_board_update' => $this->boardUpdate($params, $user),
            'kanban_board_delete' => $this->boardDelete($params, $user),
            'kanban_column_create' => $this->columnCreate($params, $user),
            'kanban_column_list' => $this->columnList($params, $user),
            'kanban_column_update' => $this->columnUpdate($params, $user),
            'kanban_column_delete' => $this->columnDelete($params, $user),
            'kanban_column_reorder' => $this->columnReorder($params, $user),
            'kanban_task_add' => $this->taskAdd($params, $user),
            'kanban_task_remove' => $this->taskRemove($params, $user),
            'kanban_task_move' => $this->taskMove($params, $user),
            'kanban_task_reorder' => $this->taskReorder($params, $user),
            'kanban_task_list' => $this->taskList($params, $user),
            'kanban_column_close_tasks' => $this->columnCloseTasks($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    // ===== Board tools =====

    /** @return array<string, mixed> */
    private function boardCreate(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);

        $name = trim($params['name'] ?? '');
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Board name is required.']]);
        }

        $board = KanbanBoard::create(['project_id' => $project->id, 'name' => $name]);

        return $board->format();
    }

    /** @return array<string, mixed> */
    private function boardList(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);

        $boards = KanbanBoard::where('project_id', $project->id)
            ->with(['columns' => fn ($q) => $q->withCount('boardTasks')])
            ->orderBy('created_at')
            ->get();

        return ['data' => $boards->map(fn (KanbanBoard $b) => array_merge(
            $b->format(),
            ['columns' => $b->columns->map->format()->all()]
        ))->all()];
    }

    /** @return array<string, mixed> */
    private function boardGet(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);

        $board->load(['columns.boardTasks.task' => fn ($q) => $q->with('artifact')]);

        $columns = $board->columns->map(function (KanbanColumn $col) {
            $colData = $col->format();
            $colData['tasks'] = $col->boardTasks->map(fn (KanbanBoardTask $bt) => array_merge(
                $bt->task->format(),
                ['position' => $bt->position]
            ))->all();

            return $colData;
        })->all();

        return array_merge($board->format(), ['columns' => $columns]);
    }

    /** @return array<string, mixed> */
    private function boardUpdate(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);

        $name = trim($params['name'] ?? '');
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Board name is required.']]);
        }

        $board->update(['name' => $name]);

        return $board->format();
    }

    /** @return array<string, mixed> */
    private function boardDelete(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);

        if ($board->hasAnyTasks()) {
            throw ValidationException::withMessages(['board' => ['Cannot delete a board that contains tasks. Remove all tasks first.']]);
        }

        $board->delete();

        return ['message' => 'Board deleted.'];
    }

    // ===== Column tools =====

    /** @return array<string, mixed> */
    private function columnCreate(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);

        $name = trim($params['name'] ?? '');
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Column name is required.']]);
        }

        $limitWarning = $params['limit_warning'] ?? null;
        $limitHard = $params['limit_hard'] ?? null;
        $this->validateLimits($limitWarning, $limitHard);

        $position = ($board->columns()->max('position') ?? -1) + 1;

        $column = KanbanColumn::create([
            'board_id' => $board->id,
            'name' => $name,
            'position' => $position,
            'limit_warning' => $limitWarning,
            'limit_hard' => $limitHard,
        ]);

        return $column->format();
    }

    /** @return array<string, mixed> */
    private function columnList(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);

        $columns = $board->columns()->withCount('boardTasks')->get();

        return ['data' => $columns->map->format()->all()];
    }

    /** @return array<string, mixed> */
    private function columnUpdate(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);
        $column = $this->findColumn($params['column_id'], $board->id);

        $data = [];

        if (isset($params['name'])) {
            $name = trim($params['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => ['Column name is required.']]);
            }
            $data['name'] = $name;
        }

        $finalWarning = array_key_exists('limit_warning', $params) ? $params['limit_warning'] : $column->limit_warning;
        $finalHard = array_key_exists('limit_hard', $params) ? $params['limit_hard'] : $column->limit_hard;

        if (array_key_exists('limit_warning', $params)) {
            $data['limit_warning'] = $params['limit_warning'];
        }
        if (array_key_exists('limit_hard', $params)) {
            $data['limit_hard'] = $params['limit_hard'];
        }

        $this->validateLimits($finalWarning, $finalHard);

        if ($data !== []) {
            $column->update($data);
        }

        return $column->format();
    }

    /** @return array<string, mixed> */
    private function columnDelete(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);
        $column = $this->findColumn($params['column_id'], $board->id);

        if ($column->boardTasks()->exists()) {
            throw ValidationException::withMessages(['column' => ['Cannot delete a column that contains tasks. Remove all tasks first.']]);
        }

        $boardId = $column->board_id;
        $column->delete();

        $this->recompactColumnPositions($boardId);

        // RM-04: auto-delete board if no columns left
        if (KanbanColumn::where('board_id', $boardId)->count() === 0) {
            KanbanBoard::where('id', $boardId)->delete();

            return ['message' => 'Column deleted. Board was empty and has been deleted.'];
        }

        return ['message' => 'Column deleted.'];
    }

    /** @return array<string, mixed> */
    private function columnReorder(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);

        $columnIds = $params['column_ids'] ?? [];
        $existingIds = $board->columns()->pluck('id')->sort()->values()->all();
        $sortedInput = collect($columnIds)->sort()->values()->all();

        if ($sortedInput !== $existingIds) {
            throw ValidationException::withMessages(['column_ids' => ['The provided column IDs do not match the board columns.']]);
        }

        foreach ($columnIds as $index => $id) {
            KanbanColumn::where('id', $id)->update(['position' => $index]);
        }

        $columns = $board->columns()->withCount('boardTasks')->get();

        return ['data' => $columns->map->format()->all()];
    }

    // ===== Task tools =====

    /** @return array<string, mixed> */
    private function taskAdd(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);

        $task = $this->resolveTask($params['task_identifier'], $project->id);

        // RM-08
        if (! $task->isStandalone()) {
            throw ValidationException::withMessages(['task' => ['Only standalone tasks (not linked to a story) can be added to a board.']]);
        }

        // CL-02
        if ($task->statut === 'closed') {
            throw ValidationException::withMessages(['task' => ['Cannot add a closed task to a board. Reopen it first.']]);
        }

        // RM-09
        if ($task->statut === 'draft') {
            $task->transitionStatus('open');
        }

        // Determine target column
        if (isset($params['column_id'])) {
            $targetColumn = $this->findColumn($params['column_id'], $board->id);
        } else {
            $targetColumn = $board->columns()->orderBy('position')->first();
            if ($targetColumn === null) {
                throw ValidationException::withMessages(['board' => ['Board has no columns. Add a column first.']]);
            }
        }

        // RM-18
        if ($targetColumn->isAtHardLimit()) {
            throw ValidationException::withMessages(['column' => ["Column has reached its hard limit ({$targetColumn->limit_hard}). Cannot add more tasks."]]);
        }

        // RM-10: remove from existing board if any
        $existing = KanbanBoardTask::where('task_id', $task->id)->first();
        if ($existing !== null) {
            $oldColumnId = $existing->column_id;
            $existing->delete();
            $this->recompactPositions($oldColumnId);
        }

        $position = ($targetColumn->boardTasks()->max('position') ?? -1) + 1;

        $boardTask = KanbanBoardTask::create([
            'column_id' => $targetColumn->id,
            'task_id' => $task->id,
            'position' => $position,
        ]);

        $boardTask->load(['column', 'task.artifact']);

        return $boardTask->format();
    }

    /** @return array<string, mixed> */
    private function taskRemove(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);
        $task = $this->resolveTask($params['task_identifier'], $project->id);

        $boardTask = KanbanBoardTask::where('task_id', $task->id)
            ->whereHas('column', fn ($q) => $q->where('board_id', $board->id))
            ->first();

        if ($boardTask === null) {
            throw ValidationException::withMessages(['task' => ['Task is not on this board.']]);
        }

        $columnId = $boardTask->column_id;
        $boardTask->delete();
        $this->recompactPositions($columnId);

        return ['message' => 'Task removed from board.'];
    }

    /** @return array<string, mixed> */
    private function taskMove(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);
        $task = $this->resolveTask($params['task_identifier'], $project->id);
        $targetColumn = $this->findColumn($params['column_id'], $board->id);

        $boardTask = KanbanBoardTask::where('task_id', $task->id)
            ->whereHas('column', fn ($q) => $q->where('board_id', $board->id))
            ->first();

        if ($boardTask === null) {
            throw ValidationException::withMessages(['task' => ['Task is not on this board.']]);
        }

        $sourceColumnId = $boardTask->column_id;
        $isInterColumn = $sourceColumnId !== $targetColumn->id;

        // RM-18: hard limit check only for inter-column moves
        if ($isInterColumn && $targetColumn->isAtHardLimit()) {
            throw ValidationException::withMessages(['column' => ["Column has reached its hard limit ({$targetColumn->limit_hard}). Cannot move tasks here."]]);
        }

        if ($isInterColumn) {
            $boardTask->column_id = $targetColumn->id;
        }

        if (isset($params['position'])) {
            $boardTask->position = $params['position'];
        } else {
            $boardTask->position = ($targetColumn->boardTasks()->where('id', '!=', $boardTask->id)->max('position') ?? -1) + 1;
        }

        $boardTask->save();

        if ($isInterColumn) {
            $this->recompactPositions($sourceColumnId);
        }
        $this->recompactPositions($targetColumn->id);

        $boardTask->load(['column', 'task.artifact']);

        return $boardTask->format();
    }

    /** @return array<string, mixed> */
    private function taskReorder(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);
        $column = $this->findColumn($params['column_id'], $board->id);

        $taskIds = $params['task_ids'] ?? [];
        $existingIds = $column->boardTasks()->pluck('task_id')->sort()->values()->all();
        $sortedInput = collect($taskIds)->sort()->values()->all();

        if ($sortedInput !== $existingIds) {
            throw ValidationException::withMessages(['task_ids' => ['The provided task IDs do not match the column tasks.']]);
        }

        foreach ($taskIds as $index => $taskId) {
            KanbanBoardTask::where('column_id', $column->id)
                ->where('task_id', $taskId)
                ->update(['position' => $index]);
        }

        $entries = $column->boardTasks()->with('task.artifact')->get();

        return ['data' => $entries->map->format()->all()];
    }

    /** @return array<string, mixed> */
    private function taskList(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);

        if (isset($params['column_id'])) {
            $column = $this->findColumn($params['column_id'], $board->id);
            $entries = $column->boardTasks()->with(['column', 'task.artifact'])->get();
        } else {
            $columnIds = $board->columns()->pluck('id');
            $entries = KanbanBoardTask::whereIn('column_id', $columnIds)
                ->with(['column', 'task.artifact'])
                ->orderBy('column_id')
                ->orderBy('position')
                ->get();
        }

        return ['data' => $entries->map->format()->all()];
    }

    /** @return array<string, mixed> */
    private function columnCloseTasks(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $board = $this->findBoard($params['board_id'], $project->id);
        $column = $this->findColumn($params['column_id'], $board->id);

        $entries = $column->boardTasks()->with('task.artifact')->get();
        $closedCount = 0;
        $skipped = [];

        foreach ($entries as $entry) {
            try {
                $entry->task->transitionStatus('closed');
                // KanbanTaskObserver handles removal from board
                $closedCount++;
            } catch (ValidationException $e) {
                $skipped[] = [
                    'identifier' => $entry->task->identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'closed_count' => $closedCount,
            'skipped' => $skipped,
        ];
    }

    // ===== Helpers =====

    private function assertCanManage(User $user): void
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages(['board' => ['You do not have permission to manage boards.']]);
        }
    }

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        if (! ProjectMember::where('project_id', $project->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        return $project;
    }

    private function findBoard(string $boardId, string $projectId): KanbanBoard
    {
        $board = KanbanBoard::where('id', $boardId)->where('project_id', $projectId)->first();

        if ($board === null) {
            throw ValidationException::withMessages(['board' => ['Board not found.']]);
        }

        return $board;
    }

    private function findColumn(string $columnId, string $boardId): KanbanColumn
    {
        $column = KanbanColumn::where('id', $columnId)->where('board_id', $boardId)->first();

        if ($column === null) {
            throw ValidationException::withMessages(['column' => ['Column not found.']]);
        }

        return $column;
    }

    private function resolveTask(string $identifier, string $projectId): Task
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Task || $model->project_id !== $projectId) {
            throw ValidationException::withMessages(['task' => ['Task not found.']]);
        }

        return $model;
    }

    private function validateLimits(?int $warning, ?int $hard): void
    {
        if ($warning !== null && $warning < 1) {
            throw ValidationException::withMessages(['limit_warning' => ['Warning limit must be a positive integer.']]);
        }

        if ($hard !== null && $hard < 1) {
            throw ValidationException::withMessages(['limit_hard' => ['Hard limit must be a positive integer.']]);
        }

        if ($warning !== null && $hard !== null && $warning >= $hard) {
            throw ValidationException::withMessages(['limit_warning' => ['Warning limit must be less than hard limit.']]);
        }
    }

    private function recompactPositions(string $columnId): void
    {
        $entries = KanbanBoardTask::where('column_id', $columnId)->orderBy('position')->get();

        foreach ($entries as $index => $entry) {
            if ($entry->position !== $index) {
                $entry->update(['position' => $index]);
            }
        }
    }

    private function recompactColumnPositions(string $boardId): void
    {
        $columns = KanbanColumn::where('board_id', $boardId)->orderBy('position')->get();

        foreach ($columns as $index => $column) {
            if ($column->position !== $index) {
                $column->update(['position' => $index]);
            }
        }
    }
}
