<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Scrum;

use App\Core\Models\ApiToken;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TenantManager;
use App\Modules\Scrum\Models\ScrumColumn;
use App\Modules\Scrum\Models\ScrumItemPlacement;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ScrumColumnCrudTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $viewerToken;

    private User $user;

    private Project $project;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = createTenant();

        $this->user = User::factory()->manager()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $this->user->apiTokens()->create([
            'name' => 'test',
            'token' => $raw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);
        $this->token = $raw['raw'];

        $viewer = User::factory()->viewer()->create(['tenant_id' => $this->tenant->id]);
        $viewerRaw = ApiToken::generateRaw();
        $viewer->apiTokens()->create([
            'name' => 'viewer',
            'token' => $viewerRaw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);
        $this->viewerToken = $viewerRaw['raw'];

        $this->project = Project::factory()->create([
            'code' => 'CRUD',
            'tenant_id' => $this->tenant->id,
            'modules' => ['scrum'],
        ]);

        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->user->id, 'position' => 'owner']);
        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $viewer->id, 'position' => 'viewer']);

        app(TenantManager::class)->setTenant($this->tenant);
    }

    // ===== Helpers =====

    private function mcpCall(string $toolName, array $arguments = [], ?string $token = null): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => ['name' => $toolName, 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function mcpListTools(?string $token = null): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/list',
        ], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function extractResult(TestResponse $response): mixed
    {
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);

        return json_decode($data['result']['content'][0]['text'], true);
    }

    private function assertError(TestResponse $response, string $contains): void
    {
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString($contains, $data['error']['message']);
    }

    /** Build board with 3 columns at positions 0,1,2 */
    private function buildThreeColumns(): array
    {
        $result = $this->extractResult($this->mcpCall('scrum_board_build', [
            'project_code' => 'CRUD',
            'columns' => [
                ['name' => 'To do'],
                ['name' => 'In progress', 'limit_warning' => 3, 'limit_hard' => 5],
                ['name' => 'Done'],
            ],
        ]));

        return $result['columns'];
    }

    private function makeSprintItemInProject(Project $project): SprintItem
    {
        $sprint = Sprint::create([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->id,
            'name' => 'Sprint Test',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'status' => 'active',
        ]);

        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $artifact = Artifact::where('artifactable_type', Story::class)
            ->where('artifactable_id', $story->id)
            ->firstOrFail();

        return SprintItem::create([
            'sprint_id' => $sprint->id,
            'artifact_id' => $artifact->id,
            'position' => 0,
        ]);
    }

    // ===== Case 1: tools/list exposes 4 new tools when module is active =====

    public function test_tools_list_exposes_four_new_tools_when_module_active(): void
    {
        $response = $this->mcpListTools();
        $data = $response->json();
        $toolNames = array_column($data['result']['tools'], 'name');

        $this->assertContains('scrum_column_create', $toolNames);
        $this->assertContains('scrum_column_update', $toolNames);
        $this->assertContains('scrum_column_delete', $toolNames);
        $this->assertContains('scrum_column_reorder', $toolNames);
    }

    // ===== Case 2: tools/list does NOT expose the 4 tools when module is inactive =====

    public function test_tools_list_hides_column_tools_when_module_not_active(): void
    {
        $projectNoScrum = Project::factory()->create([
            'code' => 'NOSCR',
            'tenant_id' => $this->tenant->id,
            'modules' => [],
        ]);
        ProjectMember::create(['project_id' => $projectNoScrum->id, 'user_id' => $this->user->id, 'position' => 'owner']);

        // The MCP session is scoped per project via the token; a call to a scrum tool on a non-scrum project
        // should return "not active for project".
        $response = $this->mcpCall('scrum_column_create', [
            'project_code' => 'NOSCR',
            'name' => 'Col',
        ]);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not active for project', $data['error']['message']);
    }

    // ===== Case 3: viewer role is rejected on all 4 mutation tools =====

    public function test_viewer_role_is_rejected_on_all_mutation_tools(): void
    {
        $columns = $this->buildThreeColumns();
        $columnId = $columns[0]['id'];

        foreach (['scrum_column_create', 'scrum_column_update', 'scrum_column_delete', 'scrum_column_reorder'] as $tool) {
            $params = match ($tool) {
                'scrum_column_create' => ['project_code' => 'CRUD', 'name' => 'X'],
                'scrum_column_update' => ['column_id' => $columnId, 'name' => 'X'],
                'scrum_column_delete' => ['column_id' => $columnId],
                'scrum_column_reorder' => ['project_code' => 'CRUD', 'column_ids' => [$columnId]],
            };

            $this->assertError(
                $this->mcpCall($tool, $params, $this->viewerToken),
                'You do not have permission to manage sprints.'
            );
        }
    }

    // ===== Case 4: scrum_column_create happy path — board with 3 columns → position 3 =====

    public function test_column_create_appends_to_existing_board(): void
    {
        $this->buildThreeColumns();

        $result = $this->extractResult($this->mcpCall('scrum_column_create', [
            'project_code' => 'CRUD',
            'name' => 'QA',
            'limit_hard' => 3,
        ]));

        $this->assertSame('QA', $result['name']);
        $this->assertSame(3, $result['position']);
        $this->assertSame(3, $result['limit_hard']);
        $this->assertNull($result['limit_warning']);
        $this->assertSame(0, $result['placement_count']);
        $this->assertFalse($result['at_warning']);
        $this->assertFalse($result['at_hard_limit']);
        $this->assertArrayHasKey('id', $result);
    }

    // ===== Case 5: scrum_column_create on project with no columns → position 0 =====

    public function test_column_create_first_column_gets_position_zero(): void
    {
        $this->assertDatabaseCount('scrum_columns', 0);

        $result = $this->extractResult($this->mcpCall('scrum_column_create', [
            'project_code' => 'CRUD',
            'name' => 'Backlog',
        ]));

        $this->assertSame('Backlog', $result['name']);
        $this->assertSame(0, $result['position']);
        $this->assertDatabaseCount('scrum_columns', 1);
    }

    // ===== Case 6: scrum_column_create rejects whitespace name =====

    public function test_column_create_rejects_whitespace_name(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_column_create', ['project_code' => 'CRUD', 'name' => '   ']),
            'Column name is required.'
        );
    }

    // ===== Case 7: scrum_column_create rejects invalid WIP limits =====

    public function test_column_create_rejects_warning_greater_than_hard(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_column_create', [
                'project_code' => 'CRUD',
                'name' => 'X',
                'limit_warning' => 5,
                'limit_hard' => 3,
            ]),
            'Warning limit must be less than hard limit.'
        );
    }

    // ===== Case 8: scrum_column_create cross-project access denied =====

    public function test_column_create_cross_project_access_denied(): void
    {
        $otherProject = Project::factory()->create([
            'code' => 'OTHER',
            'tenant_id' => $this->tenant->id,
            'modules' => ['scrum'],
        ]);
        // user is NOT a member of otherProject

        $this->assertError(
            $this->mcpCall('scrum_column_create', ['project_code' => 'OTHER', 'name' => 'X']),
            'Access denied.'
        );
    }

    // ===== Case 9: scrum_column_update — name only =====

    public function test_column_update_name_only(): void
    {
        $columns = $this->buildThreeColumns();
        $column = $columns[1]; // In progress, limit_warning=3, limit_hard=5

        $result = $this->extractResult($this->mcpCall('scrum_column_update', [
            'column_id' => $column['id'],
            'name' => 'In dev',
        ]));

        $this->assertSame('In dev', $result['name']);
        $this->assertSame(3, $result['limit_warning']);
        $this->assertSame(5, $result['limit_hard']);
        $this->assertSame($column['position'], $result['position']);
    }

    // ===== Case 10: scrum_column_update — limit_hard only (coherent with existing limit_warning) =====

    public function test_column_update_limit_hard_only_coherent_with_existing_warning(): void
    {
        $columns = $this->buildThreeColumns();
        $column = $columns[1]; // limit_warning=3, limit_hard=5

        $result = $this->extractResult($this->mcpCall('scrum_column_update', [
            'column_id' => $column['id'],
            'limit_hard' => 8,
        ]));

        $this->assertSame('In progress', $result['name']);
        $this->assertSame(3, $result['limit_warning']);
        $this->assertSame(8, $result['limit_hard']);
    }

    // ===== Case 11: scrum_column_update — limit_hard: null removes hard cap =====

    public function test_column_update_null_hard_removes_hard_limit(): void
    {
        $columns = $this->buildThreeColumns();
        $column = $columns[1]; // limit_warning=3, limit_hard=5

        $result = $this->extractResult($this->mcpCall('scrum_column_update', [
            'column_id' => $column['id'],
            'limit_hard' => null,
        ]));

        $this->assertNull($result['limit_hard']);
        $this->assertSame(3, $result['limit_warning']);
    }

    // ===== Case 12: scrum_column_update — no field provided =====

    public function test_column_update_rejects_no_fields(): void
    {
        $columns = $this->buildThreeColumns();

        $this->assertError(
            $this->mcpCall('scrum_column_update', ['column_id' => $columns[0]['id']]),
            'At least one field must be provided.'
        );
    }

    // ===== Case 13: scrum_column_update — warning > existing hard rejected =====

    public function test_column_update_rejects_warning_exceeding_existing_hard(): void
    {
        $columns = $this->buildThreeColumns();
        $column = $columns[1]; // limit_warning=3, limit_hard=5

        $this->assertError(
            $this->mcpCall('scrum_column_update', [
                'column_id' => $column['id'],
                'limit_warning' => 10,
            ]),
            'Warning limit must be less than hard limit.'
        );
    }

    // ===== Case 14: scrum_column_update — cross-tenant column_id returns not found =====

    public function test_column_update_cross_tenant_returns_not_found(): void
    {
        $otherTenant = createTenant();
        $otherProject = Project::factory()->create([
            'code' => 'XTEN',
            'tenant_id' => $otherTenant->id,
            'modules' => ['scrum'],
        ]);

        // Insert column directly in other tenant
        $foreignColumnId = Str::uuid()->toString();
        ScrumColumn::withoutGlobalScopes()->insert([
            'id' => $foreignColumnId,
            'tenant_id' => $otherTenant->id,
            'project_id' => $otherProject->id,
            'name' => 'Foreign',
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertError(
            $this->mcpCall('scrum_column_update', [
                'column_id' => $foreignColumnId,
                'name' => 'Hacked',
            ]),
            'Column not found.'
        );
    }

    // ===== Case 15: scrum_column_delete happy path — empty column =====

    public function test_column_delete_empty_column_succeeds(): void
    {
        $columns = $this->buildThreeColumns();
        $columnId = $columns[2]['id']; // Done

        $result = $this->extractResult($this->mcpCall('scrum_column_delete', [
            'column_id' => $columnId,
        ]));

        $this->assertSame('Column deleted.', $result['message']);
        $this->assertSame($columnId, $result['deleted_column_id']);
        $this->assertDatabaseMissing('scrum_columns', ['id' => $columnId]);
    }

    // ===== Case 16: scrum_column_delete — column with 1 placement is rejected =====

    public function test_column_delete_rejects_column_with_placements(): void
    {
        $columns = $this->buildThreeColumns();
        $column = ScrumColumn::find($columns[1]['id']); // In progress

        $sprintItem = $this->makeSprintItemInProject($this->project);
        ScrumItemPlacement::create([
            'sprint_item_id' => $sprintItem->id,
            'column_id' => $column->id,
            'position' => 0,
        ]);

        $this->assertError(
            $this->mcpCall('scrum_column_delete', ['column_id' => $column->id]),
            "Cannot delete column 'In progress' because it contains 1 placement(s). Move or remove the items first."
        );
    }

    // ===== Case 17: scrum_column_delete — gaps in position are left after delete =====

    public function test_column_delete_leaves_position_gaps(): void
    {
        $columns = $this->buildThreeColumns(); // positions 0,1,2
        $middle = $columns[1]; // position 1

        $this->extractResult($this->mcpCall('scrum_column_delete', [
            'column_id' => $middle['id'],
        ]));

        $remaining = ScrumColumn::where('project_id', $this->project->id)
            ->orderBy('position')
            ->pluck('position')
            ->all();

        // Positions 0 and 2 remain — gap at 1 is preserved (no compaction)
        $this->assertSame([0, 2], $remaining);
    }

    // ===== Case 18: scrum_column_delete — unknown column_id =====

    public function test_column_delete_unknown_id_returns_not_found(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_column_delete', ['column_id' => Str::uuid()->toString()]),
            'Column not found.'
        );
    }

    // ===== Case 19: scrum_column_reorder happy path =====

    public function test_column_reorder_reorders_four_columns(): void
    {
        // Build 4 columns A=0, B=1, C=2, D=3
        $built = $this->extractResult($this->mcpCall('scrum_board_build', [
            'project_code' => 'CRUD',
            'columns' => [
                ['name' => 'A'],
                ['name' => 'B'],
                ['name' => 'C'],
                ['name' => 'D'],
            ],
        ]));

        $ids = array_column($built['columns'], 'id');
        [$idA, $idB, $idC, $idD] = $ids;

        // Reorder to D=0, A=1, C=2, B=3
        $result = $this->extractResult($this->mcpCall('scrum_column_reorder', [
            'project_code' => 'CRUD',
            'column_ids' => [$idD, $idA, $idC, $idB],
        ]));

        $this->assertSame('Columns reordered.', $result['message']);
        $this->assertSame(4, $result['count']);
        $this->assertSame($idD, $result['columns'][0]['id']);
        $this->assertSame(0, $result['columns'][0]['position']);
        $this->assertSame($idA, $result['columns'][1]['id']);
        $this->assertSame(1, $result['columns'][1]['position']);
        $this->assertSame($idC, $result['columns'][2]['id']);
        $this->assertSame(2, $result['columns'][2]['position']);
        $this->assertSame($idB, $result['columns'][3]['id']);
        $this->assertSame(3, $result['columns'][3]['position']);
    }

    // ===== Case 20: scrum_column_reorder — incomplete coverage =====

    public function test_column_reorder_incomplete_coverage_returns_mismatch(): void
    {
        $columns = $this->buildThreeColumns();
        $ids = array_column($columns, 'id');

        // Only pass 2 out of 3 IDs
        $this->assertError(
            $this->mcpCall('scrum_column_reorder', [
                'project_code' => 'CRUD',
                'column_ids' => [$ids[0], $ids[1]],
            ]),
            'Reorder coverage mismatch. Missing:'
        );
    }

    // ===== Case 21: scrum_column_reorder — foreign column_id in list =====

    public function test_column_reorder_foreign_column_id_returns_mismatch(): void
    {
        $columns = $this->buildThreeColumns();
        $ids = array_column($columns, 'id');
        $foreignId = Str::uuid()->toString();

        $this->assertError(
            $this->mcpCall('scrum_column_reorder', [
                'project_code' => 'CRUD',
                'column_ids' => [$ids[0], $ids[1], $ids[2], $foreignId],
            ]),
            'Reorder coverage mismatch.'
        );
    }

    // ===== Case 22: scrum_column_reorder — duplicate column_id =====

    public function test_column_reorder_duplicate_id_is_rejected(): void
    {
        $columns = $this->buildThreeColumns();
        $ids = array_column($columns, 'id');

        $this->assertError(
            $this->mcpCall('scrum_column_reorder', [
                'project_code' => 'CRUD',
                'column_ids' => [$ids[0], $ids[1], $ids[0]],
            ]),
            "Duplicate column_id in column_ids: '{$ids[0]}'."
        );
    }

    // ===== Case 23: scrum_column_reorder — empty list =====

    public function test_column_reorder_empty_list_is_rejected(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_column_reorder', [
                'project_code' => 'CRUD',
                'column_ids' => [],
            ]),
            'column_ids cannot be empty.'
        );
    }

    // ===== Case 24: full workflow — build → create → reorder → update → delete → get =====

    public function test_full_workflow_build_create_reorder_update_delete_get(): void
    {
        // 1. Build 2 columns
        $built = $this->extractResult($this->mcpCall('scrum_board_build', [
            'project_code' => 'CRUD',
            'columns' => [['name' => 'Todo'], ['name' => 'Done']],
        ]));
        $this->assertCount(2, $built['columns']);

        // 2. Create a new column (appended at position 2)
        $created = $this->extractResult($this->mcpCall('scrum_column_create', [
            'project_code' => 'CRUD',
            'name' => 'In Review',
        ]));
        $this->assertSame(2, $created['position']);

        // 3. Reorder: Done → In Review → Todo (D=0, R=1, T=2)
        $idTodo = $built['columns'][0]['id'];
        $idDone = $built['columns'][1]['id'];
        $idReview = $created['id'];

        $reordered = $this->extractResult($this->mcpCall('scrum_column_reorder', [
            'project_code' => 'CRUD',
            'column_ids' => [$idDone, $idReview, $idTodo],
        ]));
        $this->assertSame($idDone, $reordered['columns'][0]['id']);
        $this->assertSame(0, $reordered['columns'][0]['position']);

        // 4. Update the Review column name
        $updated = $this->extractResult($this->mcpCall('scrum_column_update', [
            'column_id' => $idReview,
            'name' => 'QA Review',
        ]));
        $this->assertSame('QA Review', $updated['name']);

        // 5. Delete the Todo column (empty)
        $deleted = $this->extractResult($this->mcpCall('scrum_column_delete', [
            'column_id' => $idTodo,
        ]));
        $this->assertSame('Column deleted.', $deleted['message']);

        // 6. Get board and check final state: Done (pos0), QA Review (pos1)
        $board = $this->extractResult($this->mcpCall('scrum_board_get', ['project_code' => 'CRUD']));
        $this->assertCount(2, $board['columns']);

        $names = array_column($board['columns'], 'name');
        $this->assertContains('Done', $names);
        $this->assertContains('QA Review', $names);
        $this->assertNotContains('Todo', $names);
    }

    // ===== Case 25: cascade tenant — delete tenant removes all columns =====

    public function test_cascade_tenant_delete_removes_all_columns(): void
    {
        $this->buildThreeColumns();
        $tenantId = $this->tenant->id;

        $this->assertGreaterThan(0, ScrumColumn::where('project_id', $this->project->id)->count());

        $this->tenant->forceDelete();

        $count = ScrumColumn::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        $this->assertSame(0, $count);
    }
}
