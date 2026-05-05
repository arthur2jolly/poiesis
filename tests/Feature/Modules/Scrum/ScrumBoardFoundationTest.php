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

class ScrumBoardFoundationTest extends TestCase
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

        $this->project = Project::factory()->create([
            'code' => 'BRD',
            'tenant_id' => $this->tenant->id,
            'modules' => ['scrum'],
        ]);

        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->user->id, 'position' => 'owner']);
        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $viewer->id, 'position' => 'viewer']);

        $this->viewerToken = $viewerRaw['raw'];

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

    private function defaultColumns(): array
    {
        return [
            ['name' => 'To do'],
            ['name' => 'In progress', 'limit_warning' => 3, 'limit_hard' => 5],
            ['name' => 'Review', 'limit_hard' => 2],
            ['name' => 'Done'],
        ];
    }

    private function buildBoard(array $columns = [], ?string $code = null): mixed
    {
        return $this->extractResult($this->mcpCall('scrum_board_build', [
            'project_code' => $code ?? 'BRD',
            'columns' => $columns ?: $this->defaultColumns(),
        ]));
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
        $story = Story::factory()->create(['epic_id' => $epic->id, 'ready' => true]);
        $artifact = Artifact::where('artifactable_type', Story::class)
            ->where('artifactable_id', $story->id)
            ->firstOrFail();

        return SprintItem::create([
            'sprint_id' => $sprint->id,
            'artifact_id' => $artifact->id,
            'position' => 0,
        ]);
    }

    // ===== Case 1: scrum_board_build happy path — 4 mixed columns =====

    public function test_board_build_happy_path_four_columns(): void
    {
        $result = $this->buildBoard();

        $this->assertSame('Board built.', $result['message']);
        $this->assertSame('BRD', $result['project_code']);
        $this->assertCount(4, $result['columns']);

        $this->assertSame('To do', $result['columns'][0]['name']);
        $this->assertSame(0, $result['columns'][0]['position']);
        $this->assertNull($result['columns'][0]['limit_warning']);
        $this->assertNull($result['columns'][0]['limit_hard']);
        $this->assertSame(0, $result['columns'][0]['placement_count']);
        $this->assertFalse($result['columns'][0]['at_warning']);
        $this->assertFalse($result['columns'][0]['at_hard_limit']);

        $this->assertSame('In progress', $result['columns'][1]['name']);
        $this->assertSame(1, $result['columns'][1]['position']);
        $this->assertSame(3, $result['columns'][1]['limit_warning']);
        $this->assertSame(5, $result['columns'][1]['limit_hard']);

        $this->assertSame('Review', $result['columns'][2]['name']);
        $this->assertSame(2, $result['columns'][2]['position']);
        $this->assertNull($result['columns'][2]['limit_warning']);
        $this->assertSame(2, $result['columns'][2]['limit_hard']);

        $this->assertSame('Done', $result['columns'][3]['name']);
        $this->assertSame(3, $result['columns'][3]['position']);

        $this->assertDatabaseCount('scrum_columns', 4);
    }

    // ===== Case 2: empty columns array =====

    public function test_board_build_rejects_empty_columns(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_board_build', ['project_code' => 'BRD', 'columns' => []]),
            'At least one column is required.'
        );
    }

    // ===== Case 3: empty name =====

    public function test_board_build_rejects_empty_column_name(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'BRD',
                'columns' => [['name' => '   ']],
            ]),
            'Column name is required.'
        );
    }

    // ===== Case 4: limit_warning = 0 =====

    public function test_board_build_rejects_limit_warning_zero(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'BRD',
                'columns' => [['name' => 'Col', 'limit_warning' => 0]],
            ]),
            'Warning limit must be a positive integer.'
        );
    }

    // ===== Case 5: limit_hard = 0 =====

    public function test_board_build_rejects_limit_hard_zero(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'BRD',
                'columns' => [['name' => 'Col', 'limit_hard' => 0]],
            ]),
            'Hard limit must be a positive integer.'
        );
    }

    // ===== Case 6: limit_warning >= limit_hard =====

    public function test_board_build_rejects_warning_equal_hard(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'BRD',
                'columns' => [['name' => 'Col', 'limit_warning' => 3, 'limit_hard' => 3]],
            ]),
            'Warning limit must be less than hard limit.'
        );
    }

    public function test_board_build_rejects_warning_greater_than_hard(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'BRD',
                'columns' => [['name' => 'Col', 'limit_warning' => 5, 'limit_hard' => 3]],
            ]),
            'Warning limit must be less than hard limit.'
        );
    }

    // ===== Case 7: limit_warning only (no limit_hard) =====

    public function test_board_build_accepts_limit_warning_only(): void
    {
        $result = $this->buildBoard([['name' => 'WIP', 'limit_warning' => 3]]);

        $this->assertSame(3, $result['columns'][0]['limit_warning']);
        $this->assertNull($result['columns'][0]['limit_hard']);
    }

    // ===== Case 8: limit_hard only (no limit_warning) =====

    public function test_board_build_accepts_limit_hard_only(): void
    {
        $result = $this->buildBoard([['name' => 'WIP', 'limit_hard' => 5]]);

        $this->assertNull($result['columns'][0]['limit_warning']);
        $this->assertSame(5, $result['columns'][0]['limit_hard']);
    }

    // ===== Case 9: rebuild without placements — success =====

    public function test_board_build_rebuild_without_placements_succeeds(): void
    {
        // First build
        $this->buildBoard([['name' => 'Old Col']]);
        $this->assertDatabaseCount('scrum_columns', 1);

        // Second build (rebuild)
        $result = $this->buildBoard([['name' => 'New Col 1'], ['name' => 'New Col 2']]);

        $this->assertCount(2, $result['columns']);
        $this->assertSame('New Col 1', $result['columns'][0]['name']);
        $this->assertSame('New Col 2', $result['columns'][1]['name']);
        $this->assertDatabaseCount('scrum_columns', 2);
        $this->assertDatabaseMissing('scrum_columns', ['name' => 'Old Col']);
    }

    // ===== Case 10: rebuild WITH placements — refused =====

    public function test_board_build_rebuild_with_placements_is_refused(): void
    {
        // Build the board
        $this->buildBoard([['name' => 'To do'], ['name' => 'Done']]);
        $column = ScrumColumn::where('project_id', $this->project->id)->first();

        // Create a sprint item and place it
        $sprintItem = $this->makeSprintItemInProject($this->project);
        ScrumItemPlacement::create([
            'sprint_item_id' => $sprintItem->id,
            'column_id' => $column->id,
            'position' => 0,
        ]);

        $this->assertError(
            $this->mcpCall('scrum_board_build', ['project_code' => 'BRD', 'columns' => [['name' => 'X']]]),
            'Cannot rebuild board: items are currently placed.'
        );
    }

    // ===== Case 11: atomicity — 3rd column invalid, 0 columns created =====

    public function test_board_build_atomicity_invalid_third_column_creates_nothing(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'BRD',
                'columns' => [
                    ['name' => 'Col 1'],
                    ['name' => 'Col 2'],
                    ['name' => ''],  // invalid
                ],
            ]),
            'Column name is required.'
        );

        $this->assertDatabaseCount('scrum_columns', 0);
    }

    // ===== Case 12: insufficient permissions =====

    public function test_board_build_requires_crud_permission(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'BRD',
                'columns' => [['name' => 'Col']],
            ], $this->viewerToken),
            'You do not have permission to manage sprints.'
        );
    }

    // ===== Case 13: cross-project access denied =====

    public function test_board_build_cross_project_access_denied(): void
    {
        $otherProject = Project::factory()->create([
            'code' => 'OTHER',
            'tenant_id' => $this->tenant->id,
            'modules' => ['scrum'],
        ]);

        // user is not member of otherProject
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'OTHER',
                'columns' => [['name' => 'Col']],
            ]),
            'Access denied.'
        );
    }

    // ===== Case 14: project does not exist =====

    public function test_board_build_nonexistent_project_is_denied(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'NOPE',
                'columns' => [['name' => 'Col']],
            ]),
            'Resource not found.'
        );
    }

    // ===== Case 15: board_get — board not configured =====

    public function test_board_get_returns_empty_columns_when_not_configured(): void
    {
        $result = $this->extractResult($this->mcpCall('scrum_board_get', ['project_code' => 'BRD']));

        $this->assertSame('BRD', $result['project_code']);
        $this->assertSame([], $result['columns']);
    }

    // ===== Case 16: board_get — 4 columns, 0 placements =====

    public function test_board_get_four_columns_zero_placements(): void
    {
        $this->buildBoard();

        $result = $this->extractResult($this->mcpCall('scrum_board_get', ['project_code' => 'BRD']));

        $this->assertSame('BRD', $result['project_code']);
        $this->assertCount(4, $result['columns']);

        foreach ($result['columns'] as $col) {
            $this->assertSame(0, $col['placement_count']);
            $this->assertSame([], $col['placements']);
        }

        // Columns ordered by position
        $positions = array_column($result['columns'], 'position');
        $this->assertSame([0, 1, 2, 3], $positions);
    }

    // ===== Case 17: board_get — with placements, grouped and ordered =====

    public function test_board_get_returns_placements_grouped_by_column_ordered_by_position(): void
    {
        $this->buildBoard([['name' => 'To do'], ['name' => 'In progress']]);

        $columns = ScrumColumn::where('project_id', $this->project->id)->orderBy('position')->get();
        $todoColumn = $columns[0];
        $inProgressColumn = $columns[1];

        // Create 2 sprint items
        $item1 = $this->makeSprintItemInProject($this->project);
        $item2 = $this->makeSprintItemInProject($this->project);

        // Place item2 at position 0 in To do, item1 at position 1 in To do
        ScrumItemPlacement::create(['sprint_item_id' => $item2->id, 'column_id' => $todoColumn->id, 'position' => 0]);
        ScrumItemPlacement::create(['sprint_item_id' => $item1->id, 'column_id' => $inProgressColumn->id, 'position' => 0]);

        $result = $this->extractResult($this->mcpCall('scrum_board_get', ['project_code' => 'BRD']));

        $this->assertCount(2, $result['columns']);

        $todo = $result['columns'][0];
        $this->assertSame('To do', $todo['name']);
        $this->assertSame(1, $todo['placement_count']);
        $this->assertCount(1, $todo['placements']);
        $this->assertArrayHasKey('sprint_item', $todo['placements'][0]);

        $inProgress = $result['columns'][1];
        $this->assertSame('In progress', $inProgress['name']);
        $this->assertSame(1, $inProgress['placement_count']);
        $this->assertCount(1, $inProgress['placements']);
    }

    public function test_board_get_excludes_non_ready_stories_from_columns(): void
    {
        $this->buildBoard([['name' => 'To do']]);

        $column = ScrumColumn::where('project_id', $this->project->id)->firstOrFail();
        $sprint = Sprint::create([
            'tenant_id' => $this->project->tenant_id,
            'project_id' => $this->project->id,
            'name' => 'Sprint Test',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'status' => 'active',
        ]);
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $readyStory = Story::factory()->create(['epic_id' => $epic->id, 'titre' => 'Ready story', 'ready' => true]);
        $notReadyStory = Story::factory()->create(['epic_id' => $epic->id, 'titre' => 'Not ready story', 'ready' => false]);

        $readyItem = SprintItem::create([
            'sprint_id' => $sprint->id,
            'artifact_id' => $readyStory->artifact->id,
            'position' => 0,
        ]);
        $notReadyItem = SprintItem::create([
            'sprint_id' => $sprint->id,
            'artifact_id' => $notReadyStory->artifact->id,
            'position' => 1,
        ]);
        ScrumItemPlacement::create(['sprint_item_id' => $readyItem->id, 'column_id' => $column->id, 'position' => 0]);
        ScrumItemPlacement::create(['sprint_item_id' => $notReadyItem->id, 'column_id' => $column->id, 'position' => 1]);

        $result = $this->extractResult($this->mcpCall('scrum_board_get', ['project_code' => 'BRD']));

        $this->assertSame(1, $result['columns'][0]['placement_count']);
        $this->assertCount(1, $result['columns'][0]['placements']);
        $this->assertSame('Ready story', $result['columns'][0]['placements'][0]['sprint_item']['artifact']['title']);
        $this->assertTrue($result['columns'][0]['placements'][0]['sprint_item']['artifact']['ready']);

        $columnItems = $this->extractResult($this->mcpCall('scrum_column_items', ['column_id' => $column->id]));

        $this->assertSame(1, $columnItems['count']);
        $this->assertCount(1, $columnItems['items']);
        $this->assertSame('Ready story', $columnItems['items'][0]['sprint_item']['artifact']['title']);
        $this->assertTrue($columnItems['items'][0]['sprint_item']['artifact']['ready']);
    }

    // ===== Case 18: board_get accessible to non-CRUD member =====

    public function test_board_get_accessible_to_viewer(): void
    {
        $this->buildBoard([['name' => 'To do']]);

        $result = $this->extractResult(
            $this->mcpCall('scrum_board_get', ['project_code' => 'BRD'], $this->viewerToken)
        );

        $this->assertSame('BRD', $result['project_code']);
        $this->assertCount(1, $result['columns']);
    }

    // ===== Case 19: board_get — no sprint in project =====

    public function test_board_get_succeeds_without_any_sprint(): void
    {
        $this->buildBoard([['name' => 'Col']]);

        $this->assertDatabaseCount('scrum_sprints', 0);

        $result = $this->extractResult($this->mcpCall('scrum_board_get', ['project_code' => 'BRD']));

        $this->assertSame('BRD', $result['project_code']);
        $this->assertCount(1, $result['columns']);
    }

    // ===== Case 20: board_get — cross-tenant access denied =====

    public function test_board_get_cross_tenant_access_denied(): void
    {
        $otherTenant = createTenant();
        $otherProject = Project::factory()->create([
            'code' => 'XTEN',
            'tenant_id' => $otherTenant->id,
            'modules' => ['scrum'],
        ]);

        // Build board in other tenant's project directly
        ScrumColumn::withoutGlobalScopes()->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $otherTenant->id,
            'project_id' => $otherProject->id,
            'name' => 'To do',
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertError(
            $this->mcpCall('scrum_board_get', ['project_code' => 'XTEN']),
            'Resource not found.'
        );
    }

    // ===== Case 21: module not active — tools not exposed =====

    public function test_board_tools_not_exposed_when_module_not_active(): void
    {
        $projectNoScrum = Project::factory()->create([
            'code' => 'NOSCR',
            'tenant_id' => $this->tenant->id,
            'modules' => [],
        ]);
        ProjectMember::create(['project_id' => $projectNoScrum->id, 'user_id' => $this->user->id, 'position' => 'owner']);

        // Call board_build — module not active for this project
        $response = $this->mcpCall('scrum_board_build', [
            'project_code' => 'NOSCR',
            'columns' => [['name' => 'Col']],
        ]);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not active for project', $data['error']['message']);
    }

    // ===== Case 22: cascade — delete project removes columns and placements =====

    public function test_cascade_project_delete_removes_columns_and_placements(): void
    {
        $this->buildBoard([['name' => 'Col']]);
        $column = ScrumColumn::where('project_id', $this->project->id)->first();
        $sprintItem = $this->makeSprintItemInProject($this->project);
        ScrumItemPlacement::create(['sprint_item_id' => $sprintItem->id, 'column_id' => $column->id, 'position' => 0]);

        $this->assertDatabaseCount('scrum_columns', 1);
        $this->assertDatabaseCount('scrum_item_placements', 1);

        // Delete the project directly (bypasses soft-delete if any)
        $this->project->delete();

        $this->assertDatabaseCount('scrum_columns', 0);
        $this->assertDatabaseCount('scrum_item_placements', 0);
    }

    // ===== Case 23: coherence — build then get returns same columns in same order =====

    public function test_board_build_then_get_returns_coherent_columns(): void
    {
        $built = $this->buildBoard();
        $fetched = $this->extractResult($this->mcpCall('scrum_board_get', ['project_code' => 'BRD']));

        $this->assertSame($built['project_code'], $fetched['project_code']);
        $this->assertCount(count($built['columns']), $fetched['columns']);

        foreach ($built['columns'] as $i => $builtCol) {
            $fetchedCol = $fetched['columns'][$i];
            $this->assertSame($builtCol['id'], $fetchedCol['id']);
            $this->assertSame($builtCol['name'], $fetchedCol['name']);
            $this->assertSame($builtCol['position'], $fetchedCol['position']);
            $this->assertSame($builtCol['limit_warning'], $fetchedCol['limit_warning']);
            $this->assertSame($builtCol['limit_hard'], $fetchedCol['limit_hard']);
        }
    }
}
