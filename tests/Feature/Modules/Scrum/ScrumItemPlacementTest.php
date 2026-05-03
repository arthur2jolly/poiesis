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
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ScrumItemPlacementTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $viewerToken;

    private User $user;

    private User $viewer;

    private Project $project;

    private Project $projectB;

    private Tenant $tenant;

    private Sprint $sprint;

    /** @var array<int, SprintItem> */
    private array $sprintItems = [];

    /** @var array<string, ScrumColumn> key = 'todo'|'inprogress'|'done' */
    private array $columns = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = createTenant();

        // Manager user (can CRUD)
        $this->user = User::factory()->manager()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $this->user->apiTokens()->create([
            'name' => 'test',
            'token' => $raw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);
        $this->token = $raw['raw'];

        // Viewer user (read-only)
        $this->viewer = User::factory()->viewer()->create(['tenant_id' => $this->tenant->id]);
        $viewerRaw = ApiToken::generateRaw();
        $this->viewer->apiTokens()->create([
            'name' => 'viewer',
            'token' => $viewerRaw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);
        $this->viewerToken = $viewerRaw['raw'];

        // Project A with scrum module
        $this->project = Project::factory()->create([
            'code' => 'PLA',
            'tenant_id' => $this->tenant->id,
            'modules' => ['scrum'],
        ]);
        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->user->id, 'position' => 'owner']);
        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->viewer->id, 'position' => 'viewer']);

        // Project B (for cross-project tests)
        $this->projectB = Project::factory()->create([
            'code' => 'PLB',
            'tenant_id' => $this->tenant->id,
            'modules' => ['scrum'],
        ]);
        ProjectMember::create(['project_id' => $this->projectB->id, 'user_id' => $this->user->id, 'position' => 'owner']);

        app(TenantManager::class)->setTenant($this->tenant);

        // Sprint on project A
        $this->sprint = Sprint::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Sprint 1',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'status' => 'active',
        ]);

        // 4 stories in the sprint
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        for ($i = 0; $i < 4; $i++) {
            $story = Story::factory()->create(['epic_id' => $epic->id, 'ready' => true]);
            $artifact = Artifact::where('artifactable_type', Story::class)
                ->where('artifactable_id', $story->id)
                ->firstOrFail();
            $this->sprintItems[] = SprintItem::create([
                'sprint_id' => $this->sprint->id,
                'artifact_id' => $artifact->id,
                'position' => $i,
            ]);
        }

        // Board: To do (no limit), In progress (warning=2, hard=3), Done (no limit)
        $this->columns['todo'] = ScrumColumn::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'To do',
            'position' => 0,
        ]);
        $this->columns['inprogress'] = ScrumColumn::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'In progress',
            'position' => 1,
            'limit_warning' => 2,
            'limit_hard' => 3,
        ]);
        $this->columns['done'] = ScrumColumn::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Done',
            'position' => 2,
        ]);
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

    private function artifactIdentifier(int $itemIndex): string
    {
        $item = $this->sprintItems[$itemIndex];
        $artifact = Artifact::find($item->artifact_id);

        return (string) $artifact->identifier;
    }

    private function sprintIdentifier(): string
    {
        return $this->sprint->identifier;
    }

    private function placeItem(int $itemIndex, string $columnKey, ?int $position = null): mixed
    {
        $args = [
            'sprint_identifier' => $this->sprintIdentifier(),
            'artifact_identifier' => $this->artifactIdentifier($itemIndex),
            'column_id' => $this->columns[$columnKey]->id,
        ];
        if ($position !== null) {
            $args['position'] = $position;
        }

        return $this->extractResult($this->mcpCall('scrum_item_place', $args));
    }

    // ===== scrum_item_place tests =====

    public function test_place_happy_path_empty_column(): void
    {
        $result = $this->placeItem(0, 'todo');

        $this->assertSame('Item placed.', $result['message']);
        $this->assertSame(0, $result['placement']['position']);
        $this->assertIsArray($result['warnings']);
        $this->assertEmpty($result['warnings']);
    }

    public function test_place_append_default_position(): void
    {
        // Pre-place 3 items in the 'todo' column
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');
        $this->placeItem(2, 'todo');

        $result = $this->placeItem(3, 'todo');

        $this->assertSame(3, $result['placement']['position']);
    }

    public function test_place_insert_explicit_position(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');
        $this->placeItem(2, 'todo');

        // Insert item at position 1 — items 1 and 2 shift to 2 and 3
        $result = $this->placeItem(3, 'todo', 1);

        $this->assertSame(1, $result['placement']['position']);
        $this->assertSame(2, ScrumItemPlacement::where('sprint_item_id', $this->sprintItems[1]->id)->value('position'));
        $this->assertSame(3, ScrumItemPlacement::where('sprint_item_id', $this->sprintItems[2]->id)->value('position'));
    }

    public function test_place_insert_position_zero_shifts_all(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');

        $result = $this->placeItem(2, 'todo', 0);

        $this->assertSame(0, $result['placement']['position']);
        $this->assertSame(1, ScrumItemPlacement::where('sprint_item_id', $this->sprintItems[0]->id)->value('position'));
        $this->assertSame(2, ScrumItemPlacement::where('sprint_item_id', $this->sprintItems[1]->id)->value('position'));
    }

    public function test_place_already_placed_rejected(): void
    {
        $this->placeItem(0, 'todo');

        $this->assertError(
            $this->mcpCall('scrum_item_place', [
                'sprint_identifier' => $this->sprintIdentifier(),
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => $this->columns['todo']->id,
            ]),
            'Item is already placed on the board. Use scrum_item_move instead.'
        );
    }

    public function test_place_artifact_not_in_any_sprint(): void
    {
        // Create a story NOT in any sprint — use an artifact identifier that doesn't exist at all
        $this->assertError(
            $this->mcpCall('scrum_item_place', [
                'sprint_identifier' => $this->sprintIdentifier(),
                'artifact_identifier' => 'PLA-9999',
                'column_id' => $this->columns['todo']->id,
            ]),
            'Item not found in any sprint.'
        );
    }

    public function test_place_artifact_not_in_specified_sprint(): void
    {
        // Create a second sprint on project A
        $sprint2 = Sprint::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Sprint 2',
            'start_date' => '2026-05-20',
            'end_date' => '2026-06-03',
            'status' => 'planned',
        ]);

        $this->assertError(
            $this->mcpCall('scrum_item_place', [
                'sprint_identifier' => $sprint2->identifier,
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => $this->columns['todo']->id,
            ]),
            "Item not found in sprint '{$sprint2->identifier}'."
        );
    }

    public function test_place_column_not_found(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_item_place', [
                'sprint_identifier' => $this->sprintIdentifier(),
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            ]),
            'Column not found.'
        );
    }

    public function test_place_cross_project_item_and_column(): void
    {
        // Create a column on project B
        $columnB = ScrumColumn::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->projectB->id,
            'name' => 'Backlog',
            'position' => 0,
        ]);

        $this->assertError(
            $this->mcpCall('scrum_item_place', [
                'sprint_identifier' => $this->sprintIdentifier(),
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => $columnB->id,
            ]),
            'Item and column belong to different projects.'
        );
    }

    public function test_place_hard_limit_blocks(): void
    {
        // Fill 'inprogress' column to hard limit (3)
        $this->placeItem(0, 'inprogress');
        $this->placeItem(1, 'inprogress');
        $this->placeItem(2, 'inprogress');

        // Attempt to add a 4th story — need a 5th sprint item
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id, 'ready' => true]);
        $artifact = Artifact::where('artifactable_type', Story::class)
            ->where('artifactable_id', $story->id)
            ->firstOrFail();
        $extraItem = SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $artifact->id,
            'position' => 4,
        ]);

        $this->assertError(
            $this->mcpCall('scrum_item_place', [
                'sprint_identifier' => $this->sprintIdentifier(),
                'artifact_identifier' => (string) $artifact->identifier,
                'column_id' => $this->columns['inprogress']->id,
            ]),
            "Column 'In progress' has reached its hard limit (3). Cannot place more items."
        );
    }

    public function test_place_warning_limit_signaled(): void
    {
        // Place 1 item first
        $this->placeItem(0, 'inprogress');

        // Place 2nd item — hits warning=2
        $result = $this->placeItem(1, 'inprogress');

        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('column_warning_limit', $result['warnings'][0]['type']);
        $this->assertSame(2, $result['warnings'][0]['count']);
        $this->assertSame(2, $result['warnings'][0]['limit_warning']);
    }

    public function test_place_no_warnings_below_threshold(): void
    {
        $result = $this->placeItem(0, 'inprogress');

        $this->assertEmpty($result['warnings']);
    }

    public function test_place_negative_position_rejected(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_item_place', [
                'sprint_identifier' => $this->sprintIdentifier(),
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => $this->columns['todo']->id,
                'position' => -1,
            ]),
            'Position must be a non-negative integer.'
        );
    }

    public function test_place_position_clamped_above_count(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');

        // position 999 should be clamped to count=2
        $result = $this->placeItem(2, 'todo', 999);

        $this->assertSame(2, $result['placement']['position']);
    }

    public function test_place_permission_denied_for_viewer(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_item_place', [
                'sprint_identifier' => $this->sprintIdentifier(),
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => $this->columns['todo']->id,
            ], $this->viewerToken),
            'You do not have permission to manage sprints.'
        );
    }

    // ===== scrum_item_move tests =====

    public function test_move_intra_column_reorder(): void
    {
        // Place 3 items: positions 0,1,2
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');
        $this->placeItem(2, 'todo');

        // Move item at position 2 to position 0
        $result = $this->extractResult($this->mcpCall('scrum_item_move', [
            'artifact_identifier' => $this->artifactIdentifier(2),
            'column_id' => $this->columns['todo']->id,
            'position' => 0,
        ]));

        $this->assertSame('Item moved.', $result['message']);
        $this->assertSame(0, $result['placement']['position']);
        $this->assertNull($result['from_column_id']); // intra-column

        // Verify contiguous 0..2
        $positions = ScrumItemPlacement::where('column_id', $this->columns['todo']->id)
            ->orderBy('position')
            ->pluck('position')
            ->all();
        $this->assertSame([0, 1, 2], $positions);
    }

    public function test_move_inter_column_append(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');
        $this->placeItem(2, 'inprogress');

        // Move item[0] from todo to inprogress (no position = append)
        $result = $this->extractResult($this->mcpCall('scrum_item_move', [
            'artifact_identifier' => $this->artifactIdentifier(0),
            'column_id' => $this->columns['inprogress']->id,
        ]));

        $this->assertSame('Item moved.', $result['message']);
        $this->assertSame($this->columns['todo']->id, $result['from_column_id']);
        $this->assertSame($this->columns['inprogress']->id, $result['to_column_id']);

        // 'todo' should only have item[1] at position 0
        $this->assertSame(1, ScrumItemPlacement::where('column_id', $this->columns['todo']->id)->count());
        $this->assertSame(0, ScrumItemPlacement::where('sprint_item_id', $this->sprintItems[1]->id)->value('position'));

        // 'inprogress' should have item[2] at 0 and item[0] at 1
        $this->assertSame(2, ScrumItemPlacement::where('column_id', $this->columns['inprogress']->id)->count());
    }

    public function test_move_inter_column_explicit_position_zero(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'inprogress');
        $this->placeItem(2, 'inprogress');

        $result = $this->extractResult($this->mcpCall('scrum_item_move', [
            'artifact_identifier' => $this->artifactIdentifier(0),
            'column_id' => $this->columns['inprogress']->id,
            'position' => 0,
        ]));

        $this->assertSame(0, $result['placement']['position']);
        // items[1] and [2] should shift to 1 and 2
        $this->assertSame(1, ScrumItemPlacement::where('sprint_item_id', $this->sprintItems[1]->id)->value('position'));
        $this->assertSame(2, ScrumItemPlacement::where('sprint_item_id', $this->sprintItems[2]->id)->value('position'));
    }

    public function test_move_not_placed_rejected(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_item_move', [
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => $this->columns['todo']->id,
            ]),
            'Item is not placed on the board. Use scrum_item_place first.'
        );
    }

    public function test_move_inter_column_hard_limit_blocks(): void
    {
        // Fill inprogress to hard limit
        $this->placeItem(0, 'inprogress');
        $this->placeItem(1, 'inprogress');
        $this->placeItem(2, 'inprogress');

        // Place another item in 'todo'
        $this->placeItem(3, 'todo');

        $this->assertError(
            $this->mcpCall('scrum_item_move', [
                'artifact_identifier' => $this->artifactIdentifier(3),
                'column_id' => $this->columns['inprogress']->id,
            ]),
            "Column 'In progress' has reached its hard limit (3). Cannot place more items."
        );
    }

    public function test_move_intra_column_at_hard_limit_succeeds(): void
    {
        // Fill inprogress to hard limit (3)
        $this->placeItem(0, 'inprogress');
        $this->placeItem(1, 'inprogress');
        $this->placeItem(2, 'inprogress');

        // Intra-column move should succeed (count doesn't change)
        $result = $this->extractResult($this->mcpCall('scrum_item_move', [
            'artifact_identifier' => $this->artifactIdentifier(0),
            'column_id' => $this->columns['inprogress']->id,
            'position' => 2,
        ]));

        $this->assertSame('Item moved.', $result['message']);
        $this->assertSame(3, ScrumItemPlacement::where('column_id', $this->columns['inprogress']->id)->count());
    }

    public function test_move_same_column_no_position_appends_to_end(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');
        $this->placeItem(2, 'todo');

        // Move item[0] (position 0) to same column without position → goes to end
        $result = $this->extractResult($this->mcpCall('scrum_item_move', [
            'artifact_identifier' => $this->artifactIdentifier(0),
            'column_id' => $this->columns['todo']->id,
        ]));

        $this->assertSame(2, $result['placement']['position']);
        $this->assertNull($result['from_column_id']); // intra
    }

    public function test_move_cross_project_rejected(): void
    {
        $this->placeItem(0, 'todo');

        // Create a column on project B
        $columnB = ScrumColumn::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->projectB->id,
            'name' => 'Backlog',
            'position' => 0,
        ]);

        $this->assertError(
            $this->mcpCall('scrum_item_move', [
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => $columnB->id,
            ]),
            'Item and column belong to different projects.'
        );
    }

    public function test_move_from_column_id_null_when_intra(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');

        $result = $this->extractResult($this->mcpCall('scrum_item_move', [
            'artifact_identifier' => $this->artifactIdentifier(0),
            'column_id' => $this->columns['todo']->id,
            'position' => 1,
        ]));

        $this->assertArrayHasKey('from_column_id', $result);
        $this->assertNull($result['from_column_id']);
    }

    public function test_move_warnings_post_move_on_target(): void
    {
        // Place 1 item in inprogress already
        $this->placeItem(0, 'inprogress');
        // Place item[1] in todo
        $this->placeItem(1, 'todo');

        // Move item[1] to inprogress → hits warning=2
        $result = $this->extractResult($this->mcpCall('scrum_item_move', [
            'artifact_identifier' => $this->artifactIdentifier(1),
            'column_id' => $this->columns['inprogress']->id,
        ]));

        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('column_warning_limit', $result['warnings'][0]['type']);
    }

    // ===== scrum_item_unplace tests =====

    public function test_unplace_happy_path_recompacts(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');
        $this->placeItem(2, 'todo');

        // Unplace item at position 1
        $result = $this->extractResult($this->mcpCall('scrum_item_unplace', [
            'artifact_identifier' => $this->artifactIdentifier(1),
        ]));

        $this->assertSame('Item unplaced.', $result['message']);
        $this->assertSame($this->artifactIdentifier(1), $result['artifact_identifier']);
        $this->assertSame($this->columns['todo']->id, $result['from_column_id']);

        // Remaining positions should be 0,1 (recompacted)
        $positions = ScrumItemPlacement::where('column_id', $this->columns['todo']->id)
            ->orderBy('position')
            ->pluck('position')
            ->all();
        $this->assertSame([0, 1], $positions);
    }

    public function test_unplace_not_placed_rejected(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_item_unplace', [
                'artifact_identifier' => $this->artifactIdentifier(0),
            ]),
            'Item is not placed on the board.'
        );
    }

    public function test_unplace_sprint_item_remains_in_sprint(): void
    {
        $this->placeItem(0, 'todo');

        $this->extractResult($this->mcpCall('scrum_item_unplace', [
            'artifact_identifier' => $this->artifactIdentifier(0),
        ]));

        // SprintItem should still exist
        $this->assertDatabaseHas('scrum_sprint_items', ['id' => $this->sprintItems[0]->id]);
        // Placement should be gone
        $this->assertDatabaseMissing('scrum_item_placements', ['sprint_item_id' => $this->sprintItems[0]->id]);
    }

    public function test_unplace_cross_tenant_artifact_masked(): void
    {
        // Create a second tenant with its own artifact
        $tenant2 = createTenant();
        $user2 = User::factory()->manager()->create(['tenant_id' => $tenant2->id]);
        $raw2 = ApiToken::generateRaw();
        $user2->apiTokens()->create([
            'name' => 'test2',
            'token' => $raw2['hash'],
            'tenant_id' => $tenant2->id,
        ]);

        $project2 = Project::factory()->create([
            'code' => 'T2P',
            'tenant_id' => $tenant2->id,
            'modules' => ['scrum'],
        ]);
        ProjectMember::create(['project_id' => $project2->id, 'user_id' => $user2->id, 'position' => 'owner']);

        app(TenantManager::class)->setTenant($tenant2);

        $epic2 = Epic::factory()->create(['project_id' => $project2->id]);
        $story2 = Story::factory()->create(['epic_id' => $epic2->id]);
        $artifact2 = Artifact::where('artifactable_type', Story::class)
            ->where('artifactable_id', $story2->id)
            ->firstOrFail();

        // Switch back to tenant 1 and try to unplace tenant2's artifact
        app(TenantManager::class)->setTenant($this->tenant);

        $this->assertError(
            $this->mcpCall('scrum_item_unplace', [
                'artifact_identifier' => (string) $artifact2->identifier,
            ]),
            'Item not found in any sprint.'
        );
    }

    // ===== scrum_column_items tests =====

    public function test_column_items_happy_path_sorted_by_position(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');
        $this->placeItem(2, 'todo');

        $result = $this->extractResult($this->mcpCall('scrum_column_items', [
            'column_id' => $this->columns['todo']->id,
        ]));

        $this->assertSame($this->columns['todo']->id, $result['column_id']);
        $this->assertSame('To do', $result['column_name']);
        $this->assertSame(3, $result['count']);
        $this->assertCount(3, $result['items']);
        $this->assertSame(0, $result['items'][0]['position']);
        $this->assertSame(1, $result['items'][1]['position']);
        $this->assertSame(2, $result['items'][2]['position']);
    }

    public function test_column_items_empty_column(): void
    {
        $result = $this->extractResult($this->mcpCall('scrum_column_items', [
            'column_id' => $this->columns['todo']->id,
        ]));

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['items']);
    }

    public function test_column_items_filter_by_sprint_identifier(): void
    {
        // Create a second sprint
        $sprint2 = Sprint::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Sprint 2',
            'start_date' => '2026-05-20',
            'end_date' => '2026-06-03',
            'status' => 'planned',
        ]);

        // Add a story to sprint2
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story2 = Story::factory()->create(['epic_id' => $epic->id, 'ready' => true]);
        $artifact2 = Artifact::where('artifactable_type', Story::class)
            ->where('artifactable_id', $story2->id)
            ->firstOrFail();
        $sprint2Item = SprintItem::create([
            'sprint_id' => $sprint2->id,
            'artifact_id' => $artifact2->id,
            'position' => 0,
        ]);

        // Place items[0] (sprint1) and sprint2Item in 'todo'
        $this->placeItem(0, 'todo');
        ScrumItemPlacement::create([
            'sprint_item_id' => $sprint2Item->id,
            'column_id' => $this->columns['todo']->id,
            'position' => 1,
        ]);

        // Filter by sprint1 — should only return item[0]
        $result = $this->extractResult($this->mcpCall('scrum_column_items', [
            'column_id' => $this->columns['todo']->id,
            'sprint_identifier' => $this->sprintIdentifier(),
        ]));

        $this->assertSame(1, $result['count']);
        $this->assertSame($this->sprintIdentifier(), $result['sprint_identifier']);
    }

    public function test_column_items_invalid_sprint_identifier(): void
    {
        $this->assertError(
            $this->mcpCall('scrum_column_items', [
                'column_id' => $this->columns['todo']->id,
                'sprint_identifier' => 'PLA-S999',
            ]),
            "Sprint 'PLA-S999' not found in project."
        );
    }

    public function test_column_items_read_allowed_for_viewer(): void
    {
        $this->placeItem(0, 'todo');

        $result = $this->extractResult($this->mcpCall('scrum_column_items', [
            'column_id' => $this->columns['todo']->id,
        ], $this->viewerToken));

        $this->assertSame(1, $result['count']);
    }

    public function test_column_items_at_warning_and_hard_limit_flags(): void
    {
        // Place 2 items in inprogress (warning=2, hard=3)
        $this->placeItem(0, 'inprogress');
        $this->placeItem(1, 'inprogress');

        $result = $this->extractResult($this->mcpCall('scrum_column_items', [
            'column_id' => $this->columns['inprogress']->id,
        ]));

        $this->assertTrue($result['at_warning']);
        $this->assertFalse($result['at_hard_limit']);
        $this->assertSame(2, $result['limit_warning']);
        $this->assertSame(3, $result['limit_hard']);

        // Add one more to reach hard limit
        $this->placeItem(2, 'inprogress');

        $result2 = $this->extractResult($this->mcpCall('scrum_column_items', [
            'column_id' => $this->columns['inprogress']->id,
        ]));

        $this->assertTrue($result2['at_hard_limit']);
    }

    public function test_column_items_no_n_plus_one_queries(): void
    {
        $this->placeItem(0, 'todo');
        $this->placeItem(1, 'todo');
        $this->placeItem(2, 'todo');

        DB::enableQueryLog();

        $this->extractResult($this->mcpCall('scrum_column_items', [
            'column_id' => $this->columns['todo']->id,
        ]));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // With eager loading, we expect a small bounded number of queries regardless of item count
        $this->assertLessThanOrEqual(20, count($queries));
    }

    // ===== Transverse scenarios =====

    public function test_tools_list_exposes_four_new_tools_when_module_active(): void
    {
        $data = $this->mcpListTools()->json();
        $toolNames = array_column($data['result']['tools'], 'name');

        $this->assertContains('scrum_item_place', $toolNames);
        $this->assertContains('scrum_item_move', $toolNames);
        $this->assertContains('scrum_item_unplace', $toolNames);
        $this->assertContains('scrum_column_items', $toolNames);
    }

    public function test_module_inactive_scrum_board_build_rejected(): void
    {
        $projectNoScrum = Project::factory()->create([
            'code' => 'NOSCR',
            'tenant_id' => $this->tenant->id,
            'modules' => [],
        ]);
        ProjectMember::create(['project_id' => $projectNoScrum->id, 'user_id' => $this->user->id, 'position' => 'owner']);

        // scrum_board_build takes project_code and will be blocked by module check
        $this->assertError(
            $this->mcpCall('scrum_board_build', [
                'project_code' => 'NOSCR',
                'columns' => [['name' => 'Todo']],
            ]),
            'not active for project'
        );
    }

    public function test_cascade_remove_from_sprint_deletes_placement(): void
    {
        $this->placeItem(0, 'todo');

        $this->assertDatabaseCount('scrum_item_placements', 1);

        // Remove item from sprint — cascade FK should delete placement
        $this->extractResult($this->mcpCall('remove_from_sprint', [
            'sprint_identifier' => $this->sprintIdentifier(),
            'item_identifier' => $this->artifactIdentifier(0),
        ]));

        $this->assertDatabaseCount('scrum_item_placements', 0);
    }

    public function test_column_delete_refuses_with_placements_then_succeeds_after_unplace(): void
    {
        $this->placeItem(0, 'todo');

        // Delete should fail
        $this->assertError(
            $this->mcpCall('scrum_column_delete', ['column_id' => $this->columns['todo']->id]),
            "Cannot delete column 'To do' because it contains 1 placement(s)."
        );

        // Unplace the item
        $this->extractResult($this->mcpCall('scrum_item_unplace', [
            'artifact_identifier' => $this->artifactIdentifier(0),
        ]));

        // Delete should now succeed
        $result = $this->extractResult($this->mcpCall('scrum_column_delete', ['column_id' => $this->columns['todo']->id]));
        $this->assertSame('Column deleted.', $result['message']);
    }

    public function test_idempotence_positions_after_place_move_unplace_cycle(): void
    {
        // Cycle: place → move intra → move inter → unplace × 3 times
        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->placeItem(0, 'todo');
            $this->placeItem(1, 'todo');

            $this->extractResult($this->mcpCall('scrum_item_move', [
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => $this->columns['todo']->id,
                'position' => 1,
            ]));

            $this->extractResult($this->mcpCall('scrum_item_move', [
                'artifact_identifier' => $this->artifactIdentifier(0),
                'column_id' => $this->columns['done']->id,
            ]));

            $this->extractResult($this->mcpCall('scrum_item_unplace', [
                'artifact_identifier' => $this->artifactIdentifier(0),
            ]));
            $this->extractResult($this->mcpCall('scrum_item_unplace', [
                'artifact_identifier' => $this->artifactIdentifier(1),
            ]));
        }

        // All columns should be empty and have no gaps
        $this->assertDatabaseCount('scrum_item_placements', 0);
    }

    public function test_scrum_tools_count_is_thirty(): void
    {
        $data = $this->mcpListTools()->json();
        $scrumTools = array_filter(
            $data['result']['tools'],
            fn ($t) => str_starts_with($t['name'], 'scrum_') || in_array($t['name'], [
                'create_sprint', 'list_sprints', 'get_sprint', 'update_sprint', 'delete_sprint',
                'start_sprint', 'close_sprint', 'cancel_sprint', 'add_to_sprint', 'remove_from_sprint',
                'list_sprint_items', 'list_backlog', 'reorder_backlog', 'estimate_story', 'mark_ready',
                'mark_unready', 'start_planning', 'add_to_planning', 'remove_from_planning', 'validate_sprint_plan',
            ], true)
        );

        $this->assertCount(30, $scrumTools);
    }
}
