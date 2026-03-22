<?php

namespace Tests\Feature\Core\Mcp;

use App\Core\Models\ApiToken;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpIntegrationTest extends TestCase
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

        $this->project = Project::factory()->create(['code' => 'MCP', 'tenant_id' => $this->tenant->id]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'position' => 'owner',
        ]);

        // Set TenantManager so factory-created artifacts get the correct tenant_id
        app(TenantManager::class)->setTenant($this->tenant);
    }

    private function mcp(string $method, array $params = [], ?string $id = '1'): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function mcpCall(string $toolName, array $arguments = []): TestResponse
    {
        return $this->mcp('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);
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

    // ===== INITIALIZE =====

    public function test_initialize_handshake(): void
    {
        $response = $this->mcp('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ]);

        $response->assertOk();
        $result = $response->json('result');
        $this->assertEquals('2025-03-26', $result['protocolVersion']);
        $this->assertEquals('Poiesis', $result['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertArrayHasKey('resources', $result['capabilities']);
    }

    // ===== TOOLS/LIST =====

    public function test_tools_list_returns_all_core_tools(): void
    {
        $response = $this->mcp('tools/list');

        $response->assertOk();
        $tools = $response->json('result.tools');
        $this->assertNotEmpty($tools);

        $names = array_column($tools, 'name');
        $this->assertContains('list_projects', $names);
        $this->assertContains('create_project', $names);
        $this->assertContains('create_epic', $names);
        $this->assertContains('create_story', $names);
        $this->assertContains('create_task', $names);
        $this->assertContains('resolve_artifact', $names);
        $this->assertContains('add_dependency', $names);
        $this->assertContains('list_available_modules', $names);
    }

    // ===== PROJECTS =====

    public function test_list_projects(): void
    {
        $result = $this->extractToolResult($this->mcpCall('list_projects'));
        $this->assertCount(1, $result['data']);
        $this->assertEquals('MCP', $result['data'][0]['code']);
    }

    public function test_create_project(): void
    {
        $result = $this->extractToolResult($this->mcpCall('create_project', [
            'code' => 'NEW',
            'titre' => 'New Project',
            'description' => 'Test desc',
        ]));

        $this->assertEquals('NEW', $result['code']);
        $this->assertEquals('New Project', $result['titre']);
        $this->assertDatabaseHas('projects', ['code' => 'NEW']);
    }

    public function test_get_project(): void
    {
        $result = $this->extractToolResult($this->mcpCall('get_project', [
            'project_code' => 'MCP',
        ]));

        $this->assertEquals('MCP', $result['code']);
    }

    public function test_update_project(): void
    {
        $result = $this->extractToolResult($this->mcpCall('update_project', [
            'project_code' => 'MCP',
            'titre' => 'Updated Title',
        ]));

        $this->assertEquals('Updated Title', $result['titre']);
    }

    public function test_delete_project(): void
    {
        $this->mcpCall('create_project', ['code' => 'DEL', 'titre' => 'To Delete']);
        $result = $this->extractToolResult($this->mcpCall('delete_project', ['project_code' => 'DEL']));

        $this->assertEquals('Project deleted.', $result['message']);
        $this->assertDatabaseMissing('projects', ['code' => 'DEL']);
    }

    public function test_create_project_invalid_code(): void
    {
        $response = $this->mcpCall('create_project', [
            'code' => 'a', // too short
            'titre' => 'Test',
        ]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_create_project_duplicate_code(): void
    {
        $response = $this->mcpCall('create_project', [
            'code' => 'MCP',
            'titre' => 'Duplicate',
        ]);

        $this->assertNotNull($response->json('error'));
    }

    // ===== EPICS =====

    public function test_create_and_list_epics(): void
    {
        $created = $this->extractToolResult($this->mcpCall('create_epic', [
            'project_code' => 'MCP',
            'titre' => 'Test Epic',
            'description' => 'Epic desc',
        ]));

        $this->assertStringStartsWith('MCP-', $created['identifier']);
        $this->assertEquals('Test Epic', $created['titre']);

        $list = $this->extractToolResult($this->mcpCall('list_epics', ['project_code' => 'MCP']));
        $this->assertCount(1, $list['data']);
    }

    public function test_get_epic(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);

        $result = $this->extractToolResult($this->mcpCall('get_epic', [
            'identifier' => $epic->identifier,
        ]));

        $this->assertEquals($epic->identifier, $result['identifier']);
    }

    public function test_update_epic(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);

        $result = $this->extractToolResult($this->mcpCall('update_epic', [
            'identifier' => $epic->identifier,
            'titre' => 'Updated Epic',
        ]));

        $this->assertEquals('Updated Epic', $result['titre']);
    }

    public function test_delete_epic(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $identifier = $epic->identifier;

        $result = $this->extractToolResult($this->mcpCall('delete_epic', [
            'identifier' => $identifier,
        ]));

        $this->assertEquals('Epic deleted.', $result['message']);
    }

    // ===== STORIES =====

    public function test_create_story(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);

        $result = $this->extractToolResult($this->mcpCall('create_story', [
            'project_code' => 'MCP',
            'epic_identifier' => $epic->identifier,
            'titre' => 'My Story',
            'type' => 'backend',
        ]));

        $this->assertStringStartsWith('MCP-', $result['identifier']);
        $this->assertEquals('My Story', $result['titre']);
        $this->assertEquals('draft', $result['statut']);
        $this->assertEquals('moyenne', $result['priorite']);
    }

    public function test_create_stories_batch(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);

        $result = $this->extractToolResult($this->mcpCall('create_stories', [
            'project_code' => 'MCP',
            'epic_identifier' => $epic->identifier,
            'stories' => [
                ['titre' => 'Story A', 'type' => 'backend', 'ordre' => 1],
                ['titre' => 'Story B', 'type' => 'frontend', 'ordre' => 2],
                ['titre' => 'Story C', 'type' => 'qa', 'ordre' => 3],
            ],
        ]));

        $this->assertCount(3, $result['data']);
        $this->assertEquals('Story A', $result['data'][0]['titre']);
    }

    public function test_create_stories_batch_atomic_failure(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);

        $response = $this->mcpCall('create_stories', [
            'project_code' => 'MCP',
            'epic_identifier' => $epic->identifier,
            'stories' => [
                ['titre' => 'Good', 'type' => 'backend'],
                ['titre' => 'Bad', 'type' => 'INVALID_TYPE'],
            ],
        ]);

        $this->assertNotNull($response->json('error'));
        // No stories should have been created
        $this->assertEquals(0, Story::whereHas('epic', fn ($q) => $q->where('project_id', $this->project->id))->count());
    }

    public function test_list_stories_with_filters(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        Story::factory()->create(['epic_id' => $epic->id, 'type' => 'backend', 'priorite' => 'haute']);
        Story::factory()->create(['epic_id' => $epic->id, 'type' => 'frontend', 'priorite' => 'basse']);

        $result = $this->extractToolResult($this->mcpCall('list_stories', [
            'project_code' => 'MCP',
            'type' => 'backend',
        ]));

        $this->assertCount(1, $result['data']);
        $this->assertEquals('backend', $result['data'][0]['type']);
    }

    public function test_update_story_status(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'draft']);

        $result = $this->extractToolResult($this->mcpCall('update_story_status', [
            'identifier' => $story->identifier,
            'statut' => 'open',
        ]));

        $this->assertEquals('open', $result['statut']);
    }

    public function test_invalid_status_transition(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);

        $response = $this->mcpCall('update_story_status', [
            'identifier' => $story->identifier,
            'statut' => 'draft',
        ]);

        $this->assertNotNull($response->json('error'));
    }

    // ===== TASKS =====

    public function test_create_standalone_task(): void
    {
        $result = $this->extractToolResult($this->mcpCall('create_task', [
            'project_code' => 'MCP',
            'titre' => 'Standalone Task',
            'type' => 'devops',
        ]));

        $this->assertStringStartsWith('MCP-', $result['identifier']);
        $this->assertTrue($result['standalone']);
    }

    public function test_create_child_task(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);

        $result = $this->extractToolResult($this->mcpCall('create_task', [
            'project_code' => 'MCP',
            'story_identifier' => $story->identifier,
            'titre' => 'Child Task',
            'type' => 'backend',
        ]));

        $this->assertFalse($result['standalone']);
    }

    public function test_create_tasks_batch(): void
    {
        $result = $this->extractToolResult($this->mcpCall('create_tasks', [
            'project_code' => 'MCP',
            'tasks' => [
                ['titre' => 'Task A', 'type' => 'backend'],
                ['titre' => 'Task B', 'type' => 'frontend'],
            ],
        ]));

        $this->assertCount(2, $result['data']);
    }

    public function test_list_tasks(): void
    {
        Task::factory()->create(['project_id' => $this->project->id, 'type' => 'qa']);

        $result = $this->extractToolResult($this->mcpCall('list_tasks', [
            'project_code' => 'MCP',
        ]));

        $this->assertCount(1, $result['data']);
    }

    // ===== ARTIFACTS =====

    public function test_resolve_artifact(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);

        $result = $this->extractToolResult($this->mcpCall('resolve_artifact', [
            'identifier' => $epic->identifier,
        ]));

        $this->assertEquals('epic', $result['type']);
        $this->assertEquals($epic->identifier, $result['identifier']);
    }

    public function test_resolve_nonexistent_artifact(): void
    {
        $response = $this->mcpCall('resolve_artifact', ['identifier' => 'MCP-9999']);

        $this->assertNotNull($response->json('error'));
    }

    public function test_search_artifacts(): void
    {
        $epic = Epic::factory()->create([
            'project_id' => $this->project->id,
            'titre' => 'Authentication epic',
        ]);

        $result = $this->extractToolResult($this->mcpCall('search_artifacts', [
            'project_code' => 'MCP',
            'q' => 'Authentication',
        ]));

        $this->assertNotEmpty($result['data']);
    }

    // ===== DEPENDENCIES =====

    public function test_add_and_list_dependency(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $storyA = Story::factory()->create(['epic_id' => $epic->id]);
        $storyB = Story::factory()->create(['epic_id' => $epic->id]);

        $this->extractToolResult($this->mcpCall('add_dependency', [
            'blocked_identifier' => $storyB->identifier,
            'blocking_identifier' => $storyA->identifier,
        ]));

        $deps = $this->extractToolResult($this->mcpCall('list_dependencies', [
            'identifier' => $storyB->identifier,
        ]));

        $this->assertCount(1, $deps['blocked_by']);
        $this->assertEquals($storyA->identifier, $deps['blocked_by'][0]['identifier']);
    }

    public function test_circular_dependency_rejected(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $storyA = Story::factory()->create(['epic_id' => $epic->id]);
        $storyB = Story::factory()->create(['epic_id' => $epic->id]);

        $this->mcpCall('add_dependency', [
            'blocked_identifier' => $storyB->identifier,
            'blocking_identifier' => $storyA->identifier,
        ]);

        $response = $this->mcpCall('add_dependency', [
            'blocked_identifier' => $storyA->identifier,
            'blocking_identifier' => $storyB->identifier,
        ]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_remove_dependency(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $storyA = Story::factory()->create(['epic_id' => $epic->id]);
        $storyB = Story::factory()->create(['epic_id' => $epic->id]);

        $this->mcpCall('add_dependency', [
            'blocked_identifier' => $storyB->identifier,
            'blocking_identifier' => $storyA->identifier,
        ]);

        $this->extractToolResult($this->mcpCall('remove_dependency', [
            'blocked_identifier' => $storyB->identifier,
            'blocking_identifier' => $storyA->identifier,
        ]));

        $deps = $this->extractToolResult($this->mcpCall('list_dependencies', [
            'identifier' => $storyB->identifier,
        ]));

        $this->assertCount(0, $deps['blocked_by']);
    }

    // ===== MODULES =====

    public function test_list_available_modules(): void
    {
        $result = $this->extractToolResult($this->mcpCall('list_available_modules'));
        $this->assertArrayHasKey('data', $result);
    }

    public function test_list_project_modules(): void
    {
        $result = $this->extractToolResult($this->mcpCall('list_project_modules', [
            'project_code' => 'MCP',
        ]));

        $this->assertArrayHasKey('data', $result);
    }

    // ===== RESOURCES =====

    public function test_resources_list(): void
    {
        $response = $this->mcp('resources/list');

        $response->assertOk();
        $resources = $response->json('result.resources');
        $this->assertNotEmpty($resources);

        $uris = array_column($resources, 'uri');
        $this->assertContains('project://{code}/overview', $uris);
        $this->assertContains('project://{code}/config', $uris);
    }

    public function test_project_overview_resource(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        Story::factory()->create(['epic_id' => $epic->id]);
        Task::factory()->create(['project_id' => $this->project->id]);

        $response = $this->mcp('resources/read', [
            'uri' => 'project://MCP/overview',
        ]);

        $response->assertOk();
        $contents = $response->json('result.contents');
        $this->assertNotEmpty($contents);

        $data = json_decode($contents[0]['text'], true);
        $this->assertEquals('MCP', $data['project_code']);
        $this->assertEquals(1, $data['epics_count']);
        $this->assertEquals(1, $data['stories_count']);
        $this->assertEquals(1, $data['tasks_count']);
    }

    public function test_project_config_resource(): void
    {
        $response = $this->mcp('resources/read', [
            'uri' => 'project://MCP/config',
        ]);

        $response->assertOk();
        $contents = $response->json('result.contents');
        $data = json_decode($contents[0]['text'], true);

        $this->assertEquals('MCP', $data['project_code']);
        $this->assertArrayHasKey('item_types', $data);
        $this->assertArrayHasKey('priorities', $data);
        $this->assertArrayHasKey('statuts', $data);
    }

    // ===== SSE STREAMING =====

    public function test_get_mcp_returns_sse(): void
    {
        $response = $this->get('/mcp', ['Authorization' => 'Bearer '.$this->token]);

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
    }

    // ===== ERROR HANDLING =====

    public function test_unknown_method_returns_error(): void
    {
        $response = $this->mcp('unknown/method');

        $response->assertOk();
        $error = $response->json('error');
        $this->assertNotNull($error);
        $this->assertEquals(-32601, $error['code']);
    }

    public function test_unknown_tool_returns_error(): void
    {
        $response = $this->mcpCall('nonexistent_tool');

        $response->assertOk();
        $error = $response->json('error');
        $this->assertNotNull($error);
        $this->assertEquals(-32001, $error['code']);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/list',
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_jsonrpc_returns_error(): void
    {
        $response = $this->postJson('/mcp', [
            'not_valid' => true,
        ], ['Authorization' => 'Bearer '.$this->token]);

        $response->assertOk();
        $this->assertNotNull($response->json('error'));
    }

    // ===== ACCESS CONTROL =====

    public function test_non_member_cannot_access_project_tools(): void
    {
        // Other user in the same tenant — no project membership
        $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $otherUser->apiTokens()->create(['name' => 'test', 'token' => $raw['hash'], 'tenant_id' => $this->tenant->id]);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_project',
                'arguments' => ['project_code' => 'MCP'],
            ],
        ], ['Authorization' => 'Bearer '.$raw['raw']]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_non_owner_cannot_delete_project(): void
    {
        $member = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $member->apiTokens()->create(['name' => 'test', 'token' => $raw['hash'], 'tenant_id' => $this->tenant->id]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $member->id,
            'position' => 'member',
        ]);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'delete_project',
                'arguments' => ['project_code' => 'MCP'],
            ],
        ], ['Authorization' => 'Bearer '.$raw['raw']]);

        $this->assertNotNull($response->json('error'));
        $this->assertDatabaseHas('projects', ['code' => 'MCP']);
    }
}
