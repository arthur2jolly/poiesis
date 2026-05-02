<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Scrum;

use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Task;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TenantManager;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SprintReadToolsTest extends TestCase
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
        $this->user->apiTokens()->create([
            'name' => 'test',
            'token' => $raw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);
        $this->token = $raw['raw'];

        $this->project = Project::factory()->create([
            'code' => 'SCR',
            'tenant_id' => $this->tenant->id,
            'modules' => ['scrum'],
        ]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'position' => 'owner',
        ]);

        app(TenantManager::class)->setTenant($this->tenant);
    }

    private function mcpCall(string $toolName, array $arguments = [], ?string $token = null): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => ['name' => $toolName, 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function extractToolResult(TestResponse $response): mixed
    {
        $response->assertOk();
        $data = $response->json();
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertArrayNotHasKey('error', $data);

        return json_decode($data['result']['content'][0]['text'], true);
    }

    private function makeSprint(array $overrides = []): Sprint
    {
        return Sprint::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Sprint X',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'capacity' => 30,
        ], $overrides));
    }

    private function createViewerToken(): string
    {
        $viewer = User::factory()->viewer()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $viewer->apiTokens()->create([
            'name' => 'viewer',
            'token' => $raw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $viewer->id,
            'position' => 'viewer',
        ]);

        return $raw['raw'];
    }

    // ===== list_sprints — happy path =====

    public function test_list_sprints_empty(): void
    {
        $result = $this->extractToolResult(
            $this->mcpCall('list_sprints', ['project_code' => 'SCR'])
        );

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['current_page']);
    }

    public function test_list_sprints_happy_path_three_sprints_ordered(): void
    {
        // Create 3 sprints with different start dates; ordering: start_date desc, sprint_number desc
        $this->makeSprint(['name' => 'Sprint 1', 'start_date' => '2026-04-01', 'end_date' => '2026-04-15']);
        $this->makeSprint(['name' => 'Sprint 2', 'start_date' => '2026-05-01', 'end_date' => '2026-05-15']);
        $this->makeSprint(['name' => 'Sprint 3', 'start_date' => '2026-06-01', 'end_date' => '2026-06-15']);

        $result = $this->extractToolResult(
            $this->mcpCall('list_sprints', ['project_code' => 'SCR'])
        );

        $this->assertCount(3, $result['data']);
        $this->assertSame(3, $result['meta']['total']);

        // Should be ordered by start_date desc
        $this->assertSame('Sprint 3', $result['data'][0]['name']);
        $this->assertSame('Sprint 2', $result['data'][1]['name']);
        $this->assertSame('Sprint 1', $result['data'][2]['name']);
    }

    public function test_list_sprints_per_page_capped_at_100(): void
    {
        $result = $this->extractToolResult(
            $this->mcpCall('list_sprints', ['project_code' => 'SCR', 'per_page' => 500])
        );

        $this->assertSame(100, $result['meta']['per_page']);
    }

    public function test_list_sprints_per_page_minimum_is_1(): void
    {
        $result = $this->extractToolResult(
            $this->mcpCall('list_sprints', ['project_code' => 'SCR', 'per_page' => 0])
        );

        $this->assertSame(1, $result['meta']['per_page']);
    }

    public function test_list_sprints_filter_by_status(): void
    {
        $this->makeSprint(['name' => 'Planned Sprint', 'status' => 'planned']);
        $this->makeSprint(['name' => 'Active Sprint', 'status' => 'active']);

        $result = $this->extractToolResult(
            $this->mcpCall('list_sprints', ['project_code' => 'SCR', 'status' => 'planned'])
        );

        $this->assertCount(1, $result['data']);
        $this->assertSame('planned', $result['data'][0]['status']);
        $this->assertSame(1, $result['meta']['total']);
    }

    public function test_list_sprints_includes_items_count(): void
    {
        $sprint = $this->makeSprint();

        $task = Task::factory()->create(['project_id' => $this->project->id]);
        $artifact = $task->artifact()->first();
        SprintItem::create([
            'sprint_id' => $sprint->id,
            'artifact_id' => $artifact->id,
            'position' => 0,
        ]);

        $result = $this->extractToolResult(
            $this->mcpCall('list_sprints', ['project_code' => 'SCR'])
        );

        $this->assertCount(1, $result['data']);
        $this->assertSame(1, $result['data'][0]['items_count']);
    }

    // ===== list_sprints — QO-3 viewer can read =====

    public function test_viewer_can_list_sprints(): void
    {
        $viewerToken = $this->createViewerToken();
        $this->makeSprint();

        $result = $this->extractToolResult(
            $this->mcpCall('list_sprints', ['project_code' => 'SCR'], $viewerToken)
        );

        $this->assertCount(1, $result['data']);
    }

    // ===== list_sprints — error cases =====

    public function test_list_sprints_invalid_status_rejected(): void
    {
        $response = $this->mcpCall('list_sprints', ['project_code' => 'SCR', 'status' => 'bogus']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
    }

    public function test_list_sprints_non_member_access_denied(): void
    {
        // Create a different user with no membership
        $outsider = User::factory()->manager()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $outsider->apiTokens()->create([
            'name' => 'outsider',
            'token' => $raw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->mcpCall('list_sprints', ['project_code' => 'SCR'], $raw['raw']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Access denied', $data['error']['message']);
    }

    public function test_list_sprints_unknown_project_code_returns_error(): void
    {
        $response = $this->mcpCall('list_sprints', ['project_code' => 'NOPE']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
    }

    // ===== get_sprint — happy path =====

    public function test_get_sprint_happy_path(): void
    {
        $sprint = $this->makeSprint(['name' => 'My Sprint']);

        $result = $this->extractToolResult(
            $this->mcpCall('get_sprint', ['identifier' => 'SCR-S1'])
        );

        $this->assertSame('SCR-S1', $result['identifier']);
        $this->assertSame('SCR', $result['project_code']);
        $this->assertSame('My Sprint', $result['name']);
        $this->assertSame('planned', $result['status']);
    }

    public function test_get_sprint_includes_items_count(): void
    {
        $sprint = $this->makeSprint();

        // Create 2 sprint items via Task (which auto-creates an Artifact)
        for ($i = 0; $i < 2; $i++) {
            $task = Task::factory()->create(['project_id' => $this->project->id]);
            $artifact = $task->artifact()->first();
            SprintItem::create([
                'sprint_id' => $sprint->id,
                'artifact_id' => $artifact->id,
                'position' => $i,
            ]);
        }

        $result = $this->extractToolResult(
            $this->mcpCall('get_sprint', ['identifier' => 'SCR-S1'])
        );

        $this->assertSame(2, $result['items_count']);
    }

    // ===== get_sprint — QO-3 viewer can read =====

    public function test_viewer_can_get_sprint(): void
    {
        $this->makeSprint();
        $viewerToken = $this->createViewerToken();

        $result = $this->extractToolResult(
            $this->mcpCall('get_sprint', ['identifier' => 'SCR-S1'], $viewerToken)
        );

        $this->assertSame('SCR-S1', $result['identifier']);
    }

    // ===== get_sprint — error cases =====

    public function test_get_sprint_invalid_identifier_format_scr_1(): void
    {
        $response = $this->mcpCall('get_sprint', ['identifier' => 'SCR-1']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid sprint identifier format', $data['error']['message']);
    }

    public function test_get_sprint_invalid_identifier_format_scr_sx(): void
    {
        $response = $this->mcpCall('get_sprint', ['identifier' => 'SCR-SX']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid sprint identifier format', $data['error']['message']);
    }

    public function test_get_sprint_invalid_identifier_format_s1(): void
    {
        $response = $this->mcpCall('get_sprint', ['identifier' => 'S1']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid sprint identifier format', $data['error']['message']);
    }

    public function test_get_sprint_unknown_returns_not_found(): void
    {
        $response = $this->mcpCall('get_sprint', ['identifier' => 'SCR-S999']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Sprint not found', $data['error']['message']);
    }

    public function test_get_sprint_cross_project_returns_not_found(): void
    {
        // Create another project in same tenant where $this->user is NOT a member
        $otherProject = Project::factory()->create([
            'code' => 'OTH',
            'tenant_id' => $this->tenant->id,
            'modules' => ['scrum'],
        ]);
        // Create a sprint in the other project
        Sprint::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $otherProject->id,
            'name' => 'Other Sprint',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ]);

        // Our user is a member of SCR but NOT OTH — should get "Sprint not found."
        $response = $this->mcpCall('get_sprint', ['identifier' => 'OTH-S1']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Sprint not found', $data['error']['message']);
    }

    // ===== Format shape =====

    public function test_format_output_shape(): void
    {
        $sprint = $this->makeSprint(['goal' => 'Deliver feature X', 'capacity' => 40]);

        $result = $this->extractToolResult(
            $this->mcpCall('get_sprint', ['identifier' => 'SCR-S1'])
        );

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('identifier', $result);
        $this->assertArrayHasKey('project_code', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('goal', $result);
        $this->assertArrayHasKey('start_date', $result);
        $this->assertArrayHasKey('end_date', $result);
        $this->assertArrayHasKey('capacity', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('items_count', $result);
        $this->assertArrayHasKey('closed_at', $result);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('updated_at', $result);

        $this->assertSame('SCR', $result['project_code']);
        $this->assertSame('Deliver feature X', $result['goal']);
        $this->assertSame(40, $result['capacity']);
        $this->assertNull($result['closed_at']);
        $this->assertSame(0, $result['items_count']);
    }
}
