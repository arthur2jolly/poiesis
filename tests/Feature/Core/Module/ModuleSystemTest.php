<?php

namespace Tests\Feature\Core\Module;

use App\Core\Contracts\ModuleInterface;
use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Core\Module\ModuleRegistry;
use App\Modules\Example\ExampleModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleSystemTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->manager()->create();
        $raw = ApiToken::generateRaw();
        $this->user->apiTokens()->create(['name' => 'test', 'token' => $raw['hash']]);
        $this->token = $raw['raw'];

        $this->project = Project::factory()->create(['code' => 'MODTEST', 'modules' => []]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);
    }

    private function mcp(string $method, array $params = [], ?string $id = '1'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function mcpCall(string $toolName, array $arguments = []): \Illuminate\Testing\TestResponse
    {
        return $this->mcp('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);
    }

    private function extractToolResult(\Illuminate\Testing\TestResponse $response): mixed
    {
        $response->assertOk();
        $data = $response->json();
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertArrayNotHasKey('error', $data);
        $text = $data['result']['content'][0]['text'];

        return json_decode($text, true);
    }

    private function extractError(\Illuminate\Testing\TestResponse $response): array
    {
        $response->assertOk();
        $data = $response->json();

        return $data['error'];
    }

    // ===== ExampleModule Registration =====

    public function test_example_module_is_registered(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        $this->assertTrue($registry->isRegistered('example'));

        $module = $registry->get('example');
        $this->assertInstanceOf(ExampleModule::class, $module);
        $this->assertEquals('Example Module', $module->name());
        $this->assertEquals('A skeleton module for demonstration', $module->description());
        $this->assertEquals([], $module->dependencies());
    }

    // ===== List Available Modules =====

    public function test_list_available_modules_via_mcp(): void
    {
        $result = $this->extractToolResult(
            $this->mcpCall('list_available_modules')
        );

        $this->assertNotEmpty($result['data']);
        $slugs = array_column($result['data'], 'slug');
        $this->assertContains('example', $slugs);
    }

    public function test_list_available_modules_via_api(): void
    {
        $response = $this->getJson('/api/v1/modules', [
            'Authorization' => 'Bearer '.$this->token,
        ]);

        $response->assertOk();
        $modules = $response->json('data');
        $slugs = array_column($modules, 'slug');
        $this->assertContains('example', $slugs);
    }

    // ===== Activation with satisfied dependencies =====

    public function test_activate_module_with_no_dependencies_succeeds(): void
    {
        $result = $this->extractToolResult(
            $this->mcpCall('activate_module', [
                'project_code' => 'MODTEST',
                'slug' => 'example',
            ])
        );

        $this->assertContains('example', $result['data']);

        $this->project->refresh();
        $this->assertContains('example', $this->project->modules);
    }

    // ===== Activation with missing dependency (CL2) =====

    public function test_activate_module_with_missing_dependency_fails(): void
    {
        // Register a module with a dependency on 'example'
        $registry = $this->app->make(ModuleRegistry::class);
        $registry->register($this->createDependentModule('dependent', ['example']));
        config(['modules' => array_merge(config('modules', []), ['dependent' => 'FakeClass'])]);

        $response = $this->mcpCall('activate_module', [
            'project_code' => 'MODTEST',
            'slug' => 'dependent',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContains("requires module 'example'", $data['error']['message']);
    }

    // ===== Deactivation with active dependents (CL3) =====

    public function test_deactivate_module_with_active_dependents_fails(): void
    {
        // Register both modules
        $registry = $this->app->make(ModuleRegistry::class);
        $registry->register($this->createDependentModule('dependent', ['example']));

        // Activate both
        $this->project->update(['modules' => ['example', 'dependent']]);

        $response = $this->mcpCall('deactivate_module', [
            'project_code' => 'MODTEST',
            'slug' => 'example',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContains("Cannot deactivate 'example'", $data['error']['message']);
    }

    // ===== Ping tool works when module active =====

    public function test_ping_tool_works_when_example_module_is_active(): void
    {
        $this->project->update(['modules' => ['example']]);

        $result = $this->extractToolResult(
            $this->mcpCall('ping', ['project_code' => 'MODTEST'])
        );

        $this->assertEquals(['message' => 'pong'], $result);
    }

    // ===== Ping tool returns error when module inactive (CL11) =====

    public function test_ping_tool_returns_error_when_module_inactive(): void
    {
        // Module NOT active for this project
        $this->project->update(['modules' => []]);

        $error = $this->extractError(
            $this->mcpCall('ping', ['project_code' => 'MODTEST'])
        );

        $this->assertEquals(-32000, $error['code']);
        $this->assertStringContains("Module 'example' is not active", $error['message']);
    }

    // ===== Data retained after deactivation (CL16) =====

    public function test_data_retained_after_module_deactivation(): void
    {
        // Activate module, create some project data
        $this->project->update(['modules' => ['example']]);

        // Create an epic (core data that should survive module deactivation)
        $this->mcpCall('create_epic', [
            'project_code' => 'MODTEST',
            'titre' => 'Epic retained test',
        ]);

        // Deactivate module
        $this->mcpCall('deactivate_module', [
            'project_code' => 'MODTEST',
            'slug' => 'example',
        ]);

        // Verify core data is still there
        $this->assertDatabaseHas('epics', [
            'project_id' => $this->project->id,
            'titre' => 'Epic retained test',
        ]);
    }

    // ===== Module tools appear in tools/list when active =====

    public function test_module_tools_appear_in_tools_list_when_active(): void
    {
        $this->project->update(['modules' => ['example']]);

        $response = $this->mcp('tools/list', ['project_code' => 'MODTEST']);
        $response->assertOk();

        $tools = $response->json('result.tools');
        $toolNames = array_column($tools, 'name');

        $this->assertContains('ping', $toolNames);
    }

    public function test_module_tools_absent_from_tools_list_when_inactive(): void
    {
        $this->project->update(['modules' => []]);

        $response = $this->mcp('tools/list', ['project_code' => 'MODTEST']);
        $response->assertOk();

        $tools = $response->json('result.tools');
        $toolNames = array_column($tools, 'name');

        $this->assertNotContains('ping', $toolNames);
    }

    // ===== API endpoint tests for dependency validation =====

    public function test_api_activate_module_with_missing_dependency_fails(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $registry->register($this->createDependentModule('dependent', ['example']));

        $response = $this->postJson(
            "/api/v1/projects/{$this->project->code}/modules",
            ['slug' => 'dependent'],
            ['Authorization' => 'Bearer '.$this->token]
        );

        $response->assertStatus(422);
        $this->assertStringContains("requires module 'example'", $response->json('message'));
    }

    public function test_api_deactivate_module_with_active_dependents_fails(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $registry->register($this->createDependentModule('dependent', ['example']));

        $this->project->update(['modules' => ['example', 'dependent']]);

        $response = $this->deleteJson(
            "/api/v1/projects/{$this->project->code}/modules/example",
            [],
            ['Authorization' => 'Bearer '.$this->token]
        );

        $response->assertStatus(422);
        $this->assertStringContains("Cannot deactivate 'example'", $response->json('message'));
    }

    // ===== Helpers =====

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }

    private function createDependentModule(string $slug, array $deps): ModuleInterface
    {
        return new class($slug, $deps) implements ModuleInterface
        {
            public function __construct(
                private readonly string $slug,
                private readonly array $deps,
            ) {}

            public function slug(): string
            {
                return $this->slug;
            }

            public function name(): string
            {
                return ucfirst($this->slug).' Module';
            }

            public function description(): string
            {
                return 'Test dependent module';
            }

            public function dependencies(): array
            {
                return $this->deps;
            }

            public function registerRoutes(): void {}

            public function registerListeners(): void {}

            public function migrationPath(): string
            {
                return '';
            }

            public function mcpTools(): array
            {
                return [];
            }
        };
    }
}
