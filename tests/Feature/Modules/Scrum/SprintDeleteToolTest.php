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

class SprintDeleteToolTest extends TestCase
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

    // ===== Happy path =====

    public function test_delete_sprint_planned_succeeds(): void
    {
        $this->makeSprint(['status' => 'planned']);

        $result = $this->extractToolResult($this->mcpCall('delete_sprint', [
            'identifier' => 'SCR-S1',
        ]));

        $this->assertSame('Sprint deleted.', $result['message']);
        $this->assertDatabaseMissing('scrum_sprints', ['sprint_number' => 1, 'project_id' => $this->project->id]);
    }

    public function test_delete_sprint_cancelled_succeeds(): void
    {
        $sprint = $this->makeSprint();
        Sprint::query()->where('id', $sprint->id)->update(['status' => 'cancelled']);

        $result = $this->extractToolResult($this->mcpCall('delete_sprint', [
            'identifier' => 'SCR-S1',
        ]));

        $this->assertSame('Sprint deleted.', $result['message']);
        $this->assertDatabaseMissing('scrum_sprints', ['id' => $sprint->id]);
    }

    public function test_delete_sprint_cascades_sprint_items(): void
    {
        $sprint = $this->makeSprint();
        $task1 = Task::factory()->create(['project_id' => $this->project->id]);
        $task2 = Task::factory()->create(['project_id' => $this->project->id]);

        SprintItem::create(['sprint_id' => $sprint->id, 'artifact_id' => $task1->artifact()->first()->id, 'position' => 0]);
        SprintItem::create(['sprint_id' => $sprint->id, 'artifact_id' => $task2->artifact()->first()->id, 'position' => 1]);

        $this->assertSame(2, SprintItem::where('sprint_id', $sprint->id)->count());

        $this->extractToolResult($this->mcpCall('delete_sprint', [
            'identifier' => 'SCR-S1',
        ]));

        $this->assertSame(0, SprintItem::where('sprint_id', $sprint->id)->count());
    }

    // ===== Edge cases =====

    public function test_delete_sprint_idempotency_second_call_not_found(): void
    {
        $this->makeSprint();

        $this->extractToolResult($this->mcpCall('delete_sprint', ['identifier' => 'SCR-S1']));

        $response = $this->mcpCall('delete_sprint', ['identifier' => 'SCR-S1']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Sprint not found', $data['error']['message']);
    }

    // ===== Error cases =====

    public function test_delete_sprint_active_refused(): void
    {
        $this->makeSprint(['status' => 'active']);

        $response = $this->mcpCall('delete_sprint', ['identifier' => 'SCR-S1']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Cannot delete a sprint that is active or completed', $data['error']['message']);
    }

    public function test_delete_sprint_completed_refused(): void
    {
        $this->makeSprint(['status' => 'completed']);

        $response = $this->mcpCall('delete_sprint', ['identifier' => 'SCR-S1']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Cannot delete a sprint that is active or completed', $data['error']['message']);
    }

    public function test_viewer_cannot_delete_sprint(): void
    {
        $this->makeSprint();
        $viewerToken = $this->createViewerToken();

        $response = $this->mcpCall('delete_sprint', ['identifier' => 'SCR-S1'], $viewerToken);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('permission to manage sprints', $data['error']['message']);
    }

    public function test_non_member_cannot_delete_sprint(): void
    {
        $this->makeSprint();

        $outsider = User::factory()->manager()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $outsider->apiTokens()->create([
            'name' => 'outsider',
            'token' => $raw['hash'],
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->mcpCall('delete_sprint', ['identifier' => 'SCR-S1'], $raw['raw']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Sprint not found', $data['error']['message']);
    }

    public function test_delete_sprint_malformed_identifier(): void
    {
        $response = $this->mcpCall('delete_sprint', ['identifier' => 'SCR-1']);
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid sprint identifier format', $data['error']['message']);
    }
}
