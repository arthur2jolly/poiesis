<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Scrum;

use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TenantManager;
use App\Modules\Scrum\Models\Sprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SprintWriteToolsTest extends TestCase
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

    // ===== create_sprint — happy path =====

    public function test_create_sprint_minimal_happy_path(): void
    {
        $result = $this->extractToolResult($this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint 1',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ]));

        $this->assertSame('SCR-S1', $result['identifier']);
        $this->assertSame('planned', $result['status']);
        $this->assertSame('SCR', $result['project_code']);
        $this->assertSame('Sprint 1', $result['name']);
        $this->assertNull($result['goal']);
        $this->assertNull($result['capacity']);

        $this->assertDatabaseHas('scrum_sprints', [
            'name' => 'Sprint 1',
            'status' => 'planned',
        ]);
    }

    public function test_create_sprint_with_goal_and_capacity(): void
    {
        $result = $this->extractToolResult($this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint Full',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'goal' => 'Ship feature X',
            'capacity' => 30,
        ]));

        $this->assertSame('Ship feature X', $result['goal']);
        $this->assertSame(30, $result['capacity']);

        $this->assertDatabaseHas('scrum_sprints', [
            'name' => 'Sprint Full',
            'goal' => 'Ship feature X',
            'capacity' => 30,
        ]);
    }

    public function test_create_sprint_generates_sequential_identifiers(): void
    {
        $result1 = $this->extractToolResult($this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint 1',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ]));

        $result2 = $this->extractToolResult($this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint 2',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-15',
        ]));

        $this->assertSame('SCR-S1', $result1['identifier']);
        $this->assertSame('SCR-S2', $result2['identifier']);
    }

    public function test_create_sprint_accepts_zero_capacity(): void
    {
        $result = $this->extractToolResult($this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint Zero',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'capacity' => 0,
        ]));

        $this->assertSame(0, $result['capacity']);
    }

    public function test_create_sprint_accepts_null_capacity_and_goal(): void
    {
        $result = $this->extractToolResult($this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint Null',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'capacity' => null,
            'goal' => null,
        ]));

        $this->assertNull($result['capacity']);
        $this->assertNull($result['goal']);
    }

    // ===== create_sprint — error cases =====

    public function test_create_sprint_rejects_blank_name(): void
    {
        $response = $this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => '   ',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Sprint name is required', $data['error']['message']);
    }

    public function test_create_sprint_rejects_invalid_date_format(): void
    {
        $response = $this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint Bad',
            'start_date' => '2026/05/01',
            'end_date' => '2026-05-15',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid date format', $data['error']['message']);
    }

    public function test_create_sprint_rejects_start_equals_end(): void
    {
        $response = $this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint Equal',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('end_date must be strictly greater than start_date', $data['error']['message']);
    }

    public function test_create_sprint_rejects_start_after_end(): void
    {
        $response = $this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint Reversed',
            'start_date' => '2026-05-15',
            'end_date' => '2026-05-01',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('end_date must be strictly greater than start_date', $data['error']['message']);
    }

    public function test_create_sprint_rejects_negative_capacity(): void
    {
        $response = $this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint Neg',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'capacity' => -1,
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Capacity must be a non-negative integer', $data['error']['message']);
    }

    public function test_viewer_cannot_create_sprint(): void
    {
        $viewerToken = $this->createViewerToken();

        $response = $this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint Viewer',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ], $viewerToken);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('permission to manage sprints', $data['error']['message']);
    }

    public function test_non_member_cannot_create_sprint(): void
    {
        $outsider = User::factory()->manager()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $outsider->apiTokens()->create([
            'name' => 'outsider',
            'token' => $raw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->mcpCall('create_sprint', [
            'project_code' => 'SCR',
            'name' => 'Sprint Outsider',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ], $raw['raw']);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Access denied', $data['error']['message']);
    }

    public function test_create_sprint_unknown_project_code_returns_error(): void
    {
        $response = $this->mcpCall('create_sprint', [
            'project_code' => 'NOPE',
            'name' => 'Sprint X',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
    }

    // ===== update_sprint — happy path =====

    public function test_update_sprint_partial_name_only(): void
    {
        $this->makeSprint(['goal' => 'Original goal', 'capacity' => 20]);

        $result = $this->extractToolResult($this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'name' => 'Updated Name',
        ]));

        $this->assertSame('Updated Name', $result['name']);
        $this->assertSame('Original goal', $result['goal']);
        $this->assertSame(20, $result['capacity']);
    }

    public function test_update_sprint_can_clear_goal_with_null(): void
    {
        $this->makeSprint(['goal' => 'Has a goal']);

        $result = $this->extractToolResult($this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'goal' => null,
        ]));

        $this->assertNull($result['goal']);

        $this->assertDatabaseHas('scrum_sprints', [
            'sprint_number' => 1,
            'goal' => null,
        ]);
    }

    public function test_update_sprint_can_clear_goal_with_empty_string(): void
    {
        $this->makeSprint(['goal' => 'Has a goal']);

        $result = $this->extractToolResult($this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'goal' => '   ',
        ]));

        $this->assertNull($result['goal']);
    }

    public function test_update_sprint_can_clear_capacity_with_null(): void
    {
        $this->makeSprint(['capacity' => 30]);

        $result = $this->extractToolResult($this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'capacity' => null,
        ]));

        $this->assertNull($result['capacity']);

        $this->assertDatabaseHas('scrum_sprints', [
            'sprint_number' => 1,
            'capacity' => null,
        ]);
    }

    public function test_update_sprint_no_op_with_only_identifier(): void
    {
        $sprint = $this->makeSprint(['name' => 'No-op Sprint']);

        $result = $this->extractToolResult($this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
        ]));

        $this->assertSame('No-op Sprint', $result['name']);
        $this->assertSame('SCR-S1', $result['identifier']);
        // DB unchanged — updated_at should not have moved (or at worst same second)
        $this->assertDatabaseHas('scrum_sprints', [
            'sprint_number' => 1,
            'name' => 'No-op Sprint',
        ]);
    }

    // ===== update_sprint — error cases =====

    public function test_update_sprint_status_key_rejected(): void
    {
        $this->makeSprint();

        $response = $this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'status' => 'active',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Status cannot be changed via update_sprint', $data['error']['message']);
    }

    public function test_update_sprint_status_key_null_also_rejected(): void
    {
        $this->makeSprint();

        $response = $this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'status' => null,
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Status cannot be changed via update_sprint', $data['error']['message']);
    }

    public function test_update_sprint_rejects_blank_name(): void
    {
        $this->makeSprint();

        $response = $this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'name' => '  ',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Sprint name is required', $data['error']['message']);
    }

    public function test_update_sprint_rejects_negative_capacity(): void
    {
        $this->makeSprint();

        $response = $this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'capacity' => -5,
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Capacity must be a non-negative integer', $data['error']['message']);
    }

    public function test_update_sprint_invalid_date_format(): void
    {
        $this->makeSprint();

        $response = $this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'start_date' => '01/05/2026',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid date format', $data['error']['message']);
    }

    public function test_update_sprint_validates_final_dates_mix(): void
    {
        // Sprint has end_date 2026-05-15; send start_date that is >= end_date
        $this->makeSprint(['start_date' => '2026-05-01', 'end_date' => '2026-05-15']);

        $response = $this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'start_date' => '2026-05-20', // now > current end_date
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('end_date must be strictly greater than start_date', $data['error']['message']);
    }

    public function test_viewer_cannot_update_sprint(): void
    {
        $this->makeSprint();
        $viewerToken = $this->createViewerToken();

        $response = $this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'name' => 'New Name',
        ], $viewerToken);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('permission to manage sprints', $data['error']['message']);
    }

    public function test_non_member_cannot_update_sprint(): void
    {
        $this->makeSprint();

        $outsider = User::factory()->manager()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $outsider->apiTokens()->create([
            'name' => 'outsider',
            'token' => $raw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->mcpCall('update_sprint', [
            'identifier' => 'SCR-S1',
            'name' => 'Hijack',
        ], $raw['raw']);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Sprint not found', $data['error']['message']);
    }
}
