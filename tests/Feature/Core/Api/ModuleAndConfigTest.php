<?php

namespace Tests\Feature\Core\Api;

use App\Core\Models\ApiToken;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAndConfigTest extends TestCase
{
    use RefreshDatabase;

    private function headers(string $token): array
    {
        return authHeader($token);
    }

    // -- Config endpoint --

    public function test_config_returns_all_business_values(): void
    {
        $response = $this->getJson('/api/v1/config');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'item_types', 'priorities', 'statuts', 'work_natures', 'project_positions', 'oauth_scopes',
        ]);
        $response->assertJsonFragment(['item_types' => config('core.item_types')]);
    }

    public function test_config_requires_no_authentication(): void
    {
        $response = $this->getJson('/api/v1/config');
        $response->assertStatus(200);
    }

    // -- Module list --

    public function test_list_available_modules(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['modules' => []]);

        $response = $this->getJson('/api/v1/modules', $this->headers($auth['token']));

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_list_active_modules_for_project(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['modules' => []]);
        $project->update(['modules' => ['sprint', 'comments']]);

        $response = $this->getJson(
            "/api/v1/projects/{$project->code}/modules",
            $this->headers($auth['token'])
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['data' => ['sprint', 'comments']]);
    }

    // -- Module activation --

    public function test_activate_unregistered_module_fails(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['modules' => []]);

        $response = $this->postJson(
            "/api/v1/projects/{$project->code}/modules",
            ['slug' => 'nonexistent'],
            $this->headers($auth['token'])
        );

        $response->assertStatus(422);
    }

    public function test_activate_already_active_module_fails(): void
    {
        config(['modules' => ['sprint' => 'App\\Modules\\Sprint\\SprintModule']]);
        $auth = createAuth();
        $project = setupProject($auth, ['modules' => []]);
        $project->update(['modules' => ['sprint']]);

        $response = $this->postJson(
            "/api/v1/projects/{$project->code}/modules",
            ['slug' => 'sprint'],
            $this->headers($auth['token'])
        );

        $response->assertStatus(422);
    }

    // -- Module deactivation --

    public function test_deactivate_module_success(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['modules' => []]);
        $project->update(['modules' => ['sprint']]);

        $response = $this->deleteJson(
            "/api/v1/projects/{$project->code}/modules/sprint",
            [],
            $this->headers($auth['token'])
        );

        $response->assertStatus(204);
        $project->refresh();
        $this->assertNotContains('sprint', $project->modules);
    }

    public function test_deactivate_inactive_module_returns_404(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['modules' => []]);

        $response = $this->deleteJson(
            "/api/v1/projects/{$project->code}/modules/sprint",
            [],
            $this->headers($auth['token'])
        );

        $response->assertStatus(404);
    }

    // -- Data retention --

    public function test_data_retained_after_module_deactivation(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['modules' => []]);
        $project->update(['modules' => ['sprint']]);

        $this->postJson(
            "/api/v1/projects/{$project->code}/epics",
            ['titre' => 'Test Epic'],
            $this->headers($auth['token'])
        )->assertStatus(201);

        $this->deleteJson(
            "/api/v1/projects/{$project->code}/modules/sprint",
            [],
            $this->headers($auth['token'])
        );

        $this->assertDatabaseHas('epics', [
            'project_id' => $project->id,
            'titre' => 'Test Epic',
        ]);
    }

    // -- Ownership restriction --

    public function test_only_owners_can_activate_modules(): void
    {
        config(['modules' => ['sprint' => 'App\\Modules\\Sprint\\SprintModule']]);
        $auth = createAuth();
        $project = setupProject($auth, ['modules' => []]);

        $member = User::factory()->create(['tenant_id' => $auth['tenant']->id]);
        $memberRaw = ApiToken::generateRaw();
        $member->apiTokens()->create(['name' => 'test', 'token' => $memberRaw['hash'], 'tenant_id' => $auth['tenant']->id]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'position' => 'member',
        ]);

        $response = $this->postJson(
            "/api/v1/projects/{$project->code}/modules",
            ['slug' => 'sprint'],
            $this->headers($memberRaw['raw'])
        );

        $response->assertStatus(403);
    }

    // -- Pagination beyond last page --

    public function test_pagination_beyond_results_returns_empty_data(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['modules' => []]);

        $response = $this->getJson(
            "/api/v1/projects/{$project->code}/epics?page=999",
            $this->headers($auth['token'])
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.current_page', 999);
        $response->assertJsonPath('meta.total', 0);
    }
}
