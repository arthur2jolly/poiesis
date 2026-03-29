<?php

namespace Tests\Feature\Modules\Kanban;

use App\Core\Models\ApiToken;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TenantManager;
use App\Modules\Kanban\Models\KanbanBoard;
use App\Modules\Kanban\Models\KanbanBoardTask;
use App\Modules\Kanban\Models\KanbanColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class KanbanToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    private Project $project;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = createTenant();

        $this->user = User::factory()->manager()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $this->user->apiTokens()->create(['name' => 'test', 'token' => $raw['hash'], 'tenant_id' => $this->tenant->id]);
        $this->token = $raw['raw'];

        $this->project = Project::factory()->create([
            'code' => 'KAN',
            'tenant_id' => $this->tenant->id,
            'modules' => ['kanban'],
        ]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'position' => 'owner',
        ]);

        app(TenantManager::class)->setTenant($this->tenant);
    }

    private function mcpCall(string $toolName, array $arguments = []): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
        ], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function extractToolResult(TestResponse $response): mixed
    {
        $response->assertOk();
        $data = $response->json();
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertArrayNotHasKey('error', $data);
        $text = $data['result']['content'][0]['text'];

        return json_decode($text, true);
    }

    /**
     * Create a standalone task in the project with the given status.
     */
    private function createStandaloneTask(string $status = 'open'): Task
    {
        return Task::factory()->create([
            'project_id' => $this->project->id,
            'story_id' => null,
            'statut' => $status,
        ]);
    }

    /**
     * Get the default board created on module activation.
     */
    private function getDefaultBoard(): KanbanBoard
    {
        return KanbanBoard::where('project_id', $this->project->id)->firstOrFail();
    }

    /**
     * Get the first column (position 0) of the default board.
     */
    private function getFirstColumn(KanbanBoard $board): KanbanColumn
    {
        return $board->columns()->orderBy('position')->firstOrFail();
    }

    // ===== BOARD DEFAULT (RM-02) =====

    public function test_default_board_created_on_module_activation(): void
    {
        $boards = KanbanBoard::where('project_id', $this->project->id)->get();
        $this->assertCount(1, $boards);

        $board = $boards->first();
        $this->assertEquals('Kanban board', $board->name);

        $columns = $board->columns()->orderBy('position')->get();
        $this->assertCount(3, $columns);
        $this->assertEquals('To Do', $columns[0]->name);
        $this->assertEquals(0, $columns[0]->position);
        $this->assertEquals('WIP', $columns[1]->name);
        $this->assertEquals(1, $columns[1]->position);
        $this->assertEquals('Done', $columns[2]->name);
        $this->assertEquals(2, $columns[2]->position);
    }

    // ===== CRUD BOARDS =====

    public function test_kanban_board_create(): void
    {
        $result = $this->extractToolResult($this->mcpCall('kanban_board_create', [
            'project_code' => 'KAN',
            'name' => 'Sprint Board',
        ]));

        $this->assertEquals('Sprint Board', $result['name']);
        $this->assertEquals($this->project->id, $result['project_id']);
        $this->assertArrayHasKey('id', $result);
        $this->assertDatabaseHas('kanban_boards', ['name' => 'Sprint Board', 'project_id' => $this->project->id]);
    }

    public function test_kanban_board_list(): void
    {
        KanbanBoard::create(['project_id' => $this->project->id, 'name' => 'Board B']);

        $result = $this->extractToolResult($this->mcpCall('kanban_board_list', [
            'project_code' => 'KAN',
        ]));

        // Default board + Board B
        $this->assertCount(2, $result['data']);
    }

    public function test_kanban_board_get(): void
    {
        $board = $this->getDefaultBoard();
        $task = $this->createStandaloneTask();
        $column = $this->getFirstColumn($board);
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task->id, 'position' => 0]);

        $result = $this->extractToolResult($this->mcpCall('kanban_board_get', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
        ]));

        $this->assertEquals($board->id, $result['id']);
        $this->assertEquals('Kanban board', $result['name']);
        $this->assertCount(3, $result['columns']);

        $firstCol = collect($result['columns'])->firstWhere('position', 0);
        $this->assertCount(1, $firstCol['tasks']);
    }

    public function test_kanban_board_update(): void
    {
        $board = $this->getDefaultBoard();

        $result = $this->extractToolResult($this->mcpCall('kanban_board_update', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'name' => 'Renamed Board',
        ]));

        $this->assertEquals('Renamed Board', $result['name']);
        $this->assertDatabaseHas('kanban_boards', ['id' => $board->id, 'name' => 'Renamed Board']);
    }

    public function test_kanban_board_delete_empty(): void
    {
        // Create an empty board (no tasks)
        $board = KanbanBoard::create(['project_id' => $this->project->id, 'name' => 'Empty Board']);

        $result = $this->extractToolResult($this->mcpCall('kanban_board_delete', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
        ]));

        $this->assertEquals('Board deleted.', $result['message']);
        $this->assertDatabaseMissing('kanban_boards', ['id' => $board->id]);
    }

    public function test_kanban_board_delete_with_tasks_is_refused(): void
    {
        $board = $this->getDefaultBoard();
        $task = $this->createStandaloneTask();
        $column = $this->getFirstColumn($board);
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task->id, 'position' => 0]);

        $response = $this->mcpCall('kanban_board_delete', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
        ]);

        $this->assertNotNull($response->json('error'));
        $this->assertDatabaseHas('kanban_boards', ['id' => $board->id]);
    }

    // ===== CRUD COLUMNS =====

    public function test_kanban_column_create(): void
    {
        $board = $this->getDefaultBoard();

        $result = $this->extractToolResult($this->mcpCall('kanban_column_create', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'name' => 'Review',
        ]));

        $this->assertEquals('Review', $result['name']);
        $this->assertEquals(3, $result['position']); // After position 2 (Done)
        $this->assertDatabaseHas('kanban_columns', ['board_id' => $board->id, 'name' => 'Review']);
    }

    public function test_kanban_column_update(): void
    {
        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);

        $result = $this->extractToolResult($this->mcpCall('kanban_column_update', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_id' => $column->id,
            'name' => 'Backlog',
            'limit_warning' => 3,
            'limit_hard' => 5,
        ]));

        $this->assertEquals('Backlog', $result['name']);
        $this->assertEquals(3, $result['limit_warning']);
        $this->assertEquals(5, $result['limit_hard']);
    }

    public function test_kanban_column_delete_empty(): void
    {
        $board = $this->getDefaultBoard();

        // Add a 4th column then delete it (to keep 3 columns and avoid auto-delete of board)
        $column = KanbanColumn::create([
            'board_id' => $board->id,
            'name' => 'Extra',
            'position' => 3,
        ]);

        $result = $this->extractToolResult($this->mcpCall('kanban_column_delete', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ]));

        $this->assertEquals('Column deleted.', $result['message']);
        $this->assertDatabaseMissing('kanban_columns', ['id' => $column->id]);
    }

    public function test_kanban_column_delete_with_tasks_is_refused(): void
    {
        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);
        $task = $this->createStandaloneTask();
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task->id, 'position' => 0]);

        $response = $this->mcpCall('kanban_column_delete', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_kanban_column_delete_last_column_auto_deletes_board(): void
    {
        // Create a board with a single column
        $board = KanbanBoard::create(['project_id' => $this->project->id, 'name' => 'Single Column Board']);
        $column = KanbanColumn::create(['board_id' => $board->id, 'name' => 'Only', 'position' => 0]);

        $result = $this->extractToolResult($this->mcpCall('kanban_column_delete', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ]));

        $this->assertStringContainsString('Board was empty and has been deleted', $result['message']);
        $this->assertDatabaseMissing('kanban_boards', ['id' => $board->id]);
        $this->assertDatabaseMissing('kanban_columns', ['id' => $column->id]);
    }

    public function test_kanban_column_reorder(): void
    {
        $board = $this->getDefaultBoard();
        $columns = $board->columns()->orderBy('position')->get();

        $this->assertCount(3, $columns);
        $originalOrder = $columns->pluck('id')->all();
        $reversedOrder = array_reverse($originalOrder);

        $result = $this->extractToolResult($this->mcpCall('kanban_column_reorder', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_ids' => $reversedOrder,
        ]));

        $this->assertCount(3, $result['data']);
        $reorderedIds = collect($result['data'])->sortBy('position')->pluck('id')->all();
        $this->assertEquals($reversedOrder, $reorderedIds);
    }

    public function test_kanban_column_reorder_rejects_invalid_ids(): void
    {
        $board = $this->getDefaultBoard();

        $response = $this->mcpCall('kanban_column_reorder', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_ids' => ['invalid-uuid-1', 'invalid-uuid-2'],
        ]);

        $this->assertNotNull($response->json('error'));
    }

    // ===== WIP LIMIT VALIDATION =====

    public function test_column_create_rejects_warning_gte_hard(): void
    {
        $board = $this->getDefaultBoard();

        $response = $this->mcpCall('kanban_column_create', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'name' => 'Bad Limits',
            'limit_warning' => 5,
            'limit_hard' => 3,
        ]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_column_create_rejects_zero_limits(): void
    {
        $board = $this->getDefaultBoard();

        $response = $this->mcpCall('kanban_column_create', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'name' => 'Zero Limit',
            'limit_hard' => 0,
        ]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_column_update_rejects_equal_warning_and_hard(): void
    {
        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);

        $response = $this->mcpCall('kanban_column_update', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_id' => $column->id,
            'limit_warning' => 4,
            'limit_hard' => 4,
        ]);

        $this->assertNotNull($response->json('error'));
    }

    // ===== TASK ADD =====

    public function test_kanban_task_add_standalone(): void
    {
        $board = $this->getDefaultBoard();
        $task = $this->createStandaloneTask('open');

        $result = $this->extractToolResult($this->mcpCall('kanban_task_add', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'task_identifier' => $task->identifier,
        ]));

        $this->assertEquals($task->identifier, $result['task']['identifier']);
        $this->assertEquals(0, $result['position']);
        $this->assertDatabaseHas('kanban_board_task', ['task_id' => $task->id]);
    }

    public function test_kanban_task_add_draft_auto_transitions_to_open(): void
    {
        $board = $this->getDefaultBoard();
        $task = $this->createStandaloneTask('draft');

        $result = $this->extractToolResult($this->mcpCall('kanban_task_add', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'task_identifier' => $task->identifier,
        ]));

        $this->assertEquals('open', $result['task']['statut']);
        $task->refresh();
        $this->assertEquals('open', $task->statut);
    }

    public function test_kanban_task_add_closed_is_refused(): void
    {
        $board = $this->getDefaultBoard();
        $task = $this->createStandaloneTask('open');
        $task->transitionStatus('closed');

        $response = $this->mcpCall('kanban_task_add', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'task_identifier' => $task->identifier,
        ]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_kanban_task_add_non_standalone_is_refused(): void
    {
        $board = $this->getDefaultBoard();

        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'story_id' => $story->id,
            'statut' => 'open',
        ]);

        $response = $this->mcpCall('kanban_task_add', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'task_identifier' => $task->identifier,
        ]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_kanban_task_add_moves_from_board_a_to_board_b(): void
    {
        $boardA = $this->getDefaultBoard();
        $boardB = KanbanBoard::create(['project_id' => $this->project->id, 'name' => 'Board B']);
        $colB = KanbanColumn::create(['board_id' => $boardB->id, 'name' => 'Todo', 'position' => 0]);

        $task = $this->createStandaloneTask('open');
        $colA = $this->getFirstColumn($boardA);
        KanbanBoardTask::create(['column_id' => $colA->id, 'task_id' => $task->id, 'position' => 0]);

        $result = $this->extractToolResult($this->mcpCall('kanban_task_add', [
            'project_code' => 'KAN',
            'board_id' => $boardB->id,
            'task_identifier' => $task->identifier,
        ]));

        $this->assertEquals($colB->id, $result['column_id']);
        // Task should no longer be in board A
        $this->assertDatabaseMissing('kanban_board_task', ['column_id' => $colA->id, 'task_id' => $task->id]);
        // Task should now be in board B
        $this->assertDatabaseHas('kanban_board_task', ['column_id' => $colB->id, 'task_id' => $task->id]);
    }

    public function test_kanban_task_add_hard_limit_reached_is_refused(): void
    {
        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);
        $column->update(['limit_hard' => 1]);

        $existingTask = $this->createStandaloneTask('open');
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $existingTask->id, 'position' => 0]);

        $newTask = $this->createStandaloneTask('open');

        $response = $this->mcpCall('kanban_task_add', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'task_identifier' => $newTask->identifier,
            'column_id' => $column->id,
        ]);

        $this->assertNotNull($response->json('error'));
    }

    // ===== TASK REMOVE =====

    public function test_kanban_task_remove(): void
    {
        $board = $this->getDefaultBoard();
        $task = $this->createStandaloneTask('open');
        $column = $this->getFirstColumn($board);
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task->id, 'position' => 0]);

        $result = $this->extractToolResult($this->mcpCall('kanban_task_remove', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'task_identifier' => $task->identifier,
        ]));

        $this->assertEquals('Task removed from board.', $result['message']);
        $this->assertDatabaseMissing('kanban_board_task', ['task_id' => $task->id]);
    }

    // ===== TASK MOVE =====

    public function test_kanban_task_move_between_columns(): void
    {
        $board = $this->getDefaultBoard();
        $columns = $board->columns()->orderBy('position')->get();
        $colToDo = $columns[0];
        $colWip = $columns[1];

        $task = $this->createStandaloneTask('open');
        KanbanBoardTask::create(['column_id' => $colToDo->id, 'task_id' => $task->id, 'position' => 0]);

        $result = $this->extractToolResult($this->mcpCall('kanban_task_move', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'task_identifier' => $task->identifier,
            'column_id' => $colWip->id,
        ]));

        $this->assertEquals($colWip->id, $result['column_id']);
        $this->assertDatabaseHas('kanban_board_task', ['column_id' => $colWip->id, 'task_id' => $task->id]);
        $this->assertDatabaseMissing('kanban_board_task', ['column_id' => $colToDo->id, 'task_id' => $task->id]);
    }

    public function test_kanban_task_move_intra_column_ignores_hard_limit(): void
    {
        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);
        $column->update(['limit_hard' => 2]);

        $task1 = $this->createStandaloneTask('open');
        $task2 = $this->createStandaloneTask('open');
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task1->id, 'position' => 0]);
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task2->id, 'position' => 1]);

        // Reorder within same column — hard limit is already reached but should not block
        $result = $this->extractToolResult($this->mcpCall('kanban_task_move', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'task_identifier' => $task1->identifier,
            'column_id' => $column->id,
            'position' => 1,
        ]));

        $this->assertEquals($column->id, $result['column_id']);
    }

    // ===== TASK LIST =====

    public function test_kanban_task_list_board(): void
    {
        $board = $this->getDefaultBoard();
        $columns = $board->columns()->orderBy('position')->get();

        $task1 = $this->createStandaloneTask('open');
        $task2 = $this->createStandaloneTask('open');
        KanbanBoardTask::create(['column_id' => $columns[0]->id, 'task_id' => $task1->id, 'position' => 0]);
        KanbanBoardTask::create(['column_id' => $columns[1]->id, 'task_id' => $task2->id, 'position' => 0]);

        $result = $this->extractToolResult($this->mcpCall('kanban_task_list', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
        ]));

        $this->assertCount(2, $result['data']);
    }

    public function test_kanban_task_list_column(): void
    {
        $board = $this->getDefaultBoard();
        $columns = $board->columns()->orderBy('position')->get();

        $task1 = $this->createStandaloneTask('open');
        $task2 = $this->createStandaloneTask('open');
        KanbanBoardTask::create(['column_id' => $columns[0]->id, 'task_id' => $task1->id, 'position' => 0]);
        KanbanBoardTask::create(['column_id' => $columns[1]->id, 'task_id' => $task2->id, 'position' => 0]);

        $result = $this->extractToolResult($this->mcpCall('kanban_task_list', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_id' => $columns[0]->id,
        ]));

        $this->assertCount(1, $result['data']);
        $this->assertEquals($task1->identifier, $result['data'][0]['task']['identifier']);
    }

    // ===== BULK CLOSE =====

    public function test_kanban_column_close_tasks(): void
    {
        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);

        $task1 = $this->createStandaloneTask('open');
        $task2 = $this->createStandaloneTask('open');
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task1->id, 'position' => 0]);
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task2->id, 'position' => 1]);

        $result = $this->extractToolResult($this->mcpCall('kanban_column_close_tasks', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ]));

        $this->assertEquals(2, $result['closed_count']);
        $this->assertEmpty($result['skipped']);

        $task1->refresh();
        $task2->refresh();
        $this->assertEquals('closed', $task1->statut);
        $this->assertEquals('closed', $task2->statut);

        // Tasks should have been removed from board (RM-13)
        $this->assertDatabaseMissing('kanban_board_task', ['task_id' => $task1->id]);
        $this->assertDatabaseMissing('kanban_board_task', ['task_id' => $task2->id]);
    }

    public function test_kanban_column_close_tasks_on_empty_column(): void
    {
        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);

        $result = $this->extractToolResult($this->mcpCall('kanban_column_close_tasks', [
            'project_code' => 'KAN',
            'board_id' => $board->id,
            'column_id' => $column->id,
        ]));

        $this->assertEquals(0, $result['closed_count']);
        $this->assertEmpty($result['skipped']);
    }

    // ===== LISTENERS =====

    public function test_task_closed_is_automatically_removed_from_board(): void
    {
        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);
        $task = $this->createStandaloneTask('open');
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task->id, 'position' => 0]);

        $task->transitionStatus('closed');

        $this->assertDatabaseMissing('kanban_board_task', ['task_id' => $task->id]);
    }

    public function test_task_attached_to_story_is_automatically_removed_from_board(): void
    {
        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);
        $task = $this->createStandaloneTask('open');
        KanbanBoardTask::create(['column_id' => $column->id, 'task_id' => $task->id, 'position' => 0]);

        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);

        $task->story_id = $story->id;
        $task->save();

        $this->assertDatabaseMissing('kanban_board_task', ['task_id' => $task->id]);
    }

    // ===== PERMISSIONS =====

    public function test_viewer_cannot_create_board(): void
    {
        $viewer = User::factory()->viewer()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $viewer->apiTokens()->create(['name' => 'test', 'token' => $raw['hash'], 'tenant_id' => $this->tenant->id]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $viewer->id,
            'position' => 'member',
        ]);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'kanban_board_create',
                'arguments' => ['project_code' => 'KAN', 'name' => 'New Board'],
            ],
        ], ['Authorization' => 'Bearer '.$raw['raw']]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_viewer_cannot_add_task_to_board(): void
    {
        $viewer = User::factory()->viewer()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $viewer->apiTokens()->create(['name' => 'test', 'token' => $raw['hash'], 'tenant_id' => $this->tenant->id]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $viewer->id,
            'position' => 'member',
        ]);

        $board = $this->getDefaultBoard();
        $task = $this->createStandaloneTask('open');

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'kanban_task_add',
                'arguments' => [
                    'project_code' => 'KAN',
                    'board_id' => $board->id,
                    'task_identifier' => $task->identifier,
                ],
            ],
        ], ['Authorization' => 'Bearer '.$raw['raw']]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_viewer_cannot_delete_column(): void
    {
        $viewer = User::factory()->viewer()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $viewer->apiTokens()->create(['name' => 'test', 'token' => $raw['hash'], 'tenant_id' => $this->tenant->id]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $viewer->id,
            'position' => 'member',
        ]);

        $board = $this->getDefaultBoard();
        $column = $this->getFirstColumn($board);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'kanban_column_delete',
                'arguments' => [
                    'project_code' => 'KAN',
                    'board_id' => $board->id,
                    'column_id' => $column->id,
                ],
            ],
        ], ['Authorization' => 'Bearer '.$raw['raw']]);

        $this->assertNotNull($response->json('error'));
    }
}
