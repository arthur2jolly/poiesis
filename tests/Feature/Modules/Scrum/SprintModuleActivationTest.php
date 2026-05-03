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

class SprintModuleActivationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

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

        app(TenantManager::class)->setTenant($this->tenant);
    }

    private function mcpCall(string $toolName, array $arguments = []): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => ['name' => $toolName, 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function mcpListTools(): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/list',
            'params' => [],
        ], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function createProjectWithModules(string $code, array $modules): Project
    {
        $project = Project::factory()->create([
            'code' => $code,
            'tenant_id' => $this->tenant->id,
            'modules' => $modules,
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $this->user->id,
            'position' => 'owner',
        ]);

        return $project;
    }

    // ===== Module activation =====

    public function test_scrum_tools_present_when_module_activated(): void
    {
        $this->createProjectWithModules('SCR', ['scrum']);

        $response = $this->mcpListTools();
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);

        $toolNames = array_column($data['result']['tools'], 'name');

        $this->assertContains('create_sprint', $toolNames);
        $this->assertContains('list_sprints', $toolNames);
        $this->assertContains('get_sprint', $toolNames);
        $this->assertContains('update_sprint', $toolNames);
        $this->assertContains('delete_sprint', $toolNames);
    }

    public function test_scrum_tools_present_in_list_when_no_project_scope(): void
    {
        // tools/list without project_code scope shows all module tools
        $response = $this->mcpListTools();
        $response->assertOk();
        $data = $response->json();

        $toolNames = array_column($data['result']['tools'], 'name');

        $this->assertContains('create_sprint', $toolNames);
        $this->assertContains('list_sprints', $toolNames);
        $this->assertContains('get_sprint', $toolNames);
        $this->assertContains('update_sprint', $toolNames);
        $this->assertContains('delete_sprint', $toolNames);
    }

    public function test_create_sprint_fails_when_module_not_activated(): void
    {
        $this->createProjectWithModules('NOK', []);

        $response = $this->mcpCall('create_sprint', [
            'project_code' => 'NOK',
            'name' => 'Sprint X',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        // McpServer returns "Module 'scrum' is not active for project 'NOK'."
        $this->assertStringContainsString('not active for project', $data['error']['message']);
    }

    public function test_list_sprints_fails_when_module_not_activated(): void
    {
        $this->createProjectWithModules('NOK', []);

        $response = $this->mcpCall('list_sprints', [
            'project_code' => 'NOK',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString("Module 'scrum' is not active for project 'NOK'.", $data['error']['message']);
    }

    public function test_identifier_sprint_tools_fail_when_module_not_activated(): void
    {
        $project = $this->createProjectWithModules('NOK', []);
        Sprint::create([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->id,
            'name' => 'Inactive Scrum Sprint',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ]);

        foreach ([
            ['get_sprint', ['identifier' => 'NOK-S1']],
            ['update_sprint', ['identifier' => 'NOK-S1', 'name' => 'Blocked update']],
            ['delete_sprint', ['identifier' => 'NOK-S1']],
        ] as [$tool, $arguments]) {
            $response = $this->mcpCall($tool, $arguments);

            $response->assertOk();
            $data = $response->json();
            $this->assertArrayHasKey('error', $data);
            $this->assertStringContainsString("Module 'scrum' is not active for project 'NOK'.", $data['error']['message']);
        }

        $this->assertDatabaseHas('scrum_sprints', [
            'project_id' => $project->id,
            'sprint_number' => 1,
            'name' => 'Inactive Scrum Sprint',
        ]);
    }

    // ===== Format shape =====

    public function test_create_sprint_format_has_exactly_13_keys(): void
    {
        $this->createProjectWithModules('SCR', ['scrum']);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_sprint',
                'arguments' => [
                    'project_code' => 'SCR',
                    'name' => 'Shape Sprint',
                    'start_date' => '2026-05-01',
                    'end_date' => '2026-05-15',
                ],
            ],
        ], ['Authorization' => 'Bearer '.$this->token]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);

        $result = json_decode($data['result']['content'][0]['text'], true);

        $expectedKeys = [
            'id', 'identifier', 'project_code', 'name', 'goal',
            'start_date', 'end_date', 'capacity', 'status',
            'items_count', 'closed_at', 'created_at', 'updated_at',
        ];

        $this->assertCount(13, $result);

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }

        // items_count is null on creation (no withCount called in sprintCreate)
        $this->assertNull($result['items_count']);
        $this->assertSame('planned', $result['status']);
        $this->assertSame('SCR', $result['project_code']);
        $this->assertNull($result['closed_at']);
    }
}
