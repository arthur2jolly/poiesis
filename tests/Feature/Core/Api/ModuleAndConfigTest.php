<?php

namespace Tests\Feature\Core\Api;

use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAndConfigTest extends TestCase
{
    use RefreshDatabase;

    private function createAuth(): array
    {
        $user = User::factory()->create();
        $raw = ApiToken::generateRaw();
        $user->apiTokens()->create(['name' => 'test', 'token' => $raw['hash']]);

        return ['user' => $user, 'token' => $raw['raw']];
    }

    private function setupProject(): array
    {
        $auth = $this->createAuth();
        $project = Project::factory()->create(['modules' => []]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $auth['user']->id,
            'role' => 'owner',
        ]);

        return array_merge($auth, ['project' => $project]);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    // -- Config endpoint --

    public function test_config_returns_all_business_values(): void
    {
        $response = $this->getJson('/api/v1/config');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'item_types', 'priorities', 'statuts', 'work_natures', 'project_roles', 'oauth_scopes',
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
        $data = $this->setupProject();

        $response = $this->getJson('/api/v1/modules', $this->headers($data['token']));

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_list_active_modules_for_project(): void
    {
        $data = $this->setupProject();
        $data['project']->update(['modules' => ['sprint', 'comments']]);

        $response = $this->getJson(
            "/api/v1/projects/{$data['project']->code}/modules",
            $this->headers($data['token'])
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['data' => ['sprint', 'comments']]);
    }

    // -- Module activation --

    public function test_activate_unregistered_module_fails(): void
    {
        $data = $this->setupProject();

        $response = $this->postJson(
            "/api/v1/projects/{$data['project']->code}/modules",
            ['slug' => 'nonexistent'],
            $this->headers($data['token'])
        );

        $response->assertStatus(422);
    }

    public function test_activate_already_active_module_fails(): void
    {
        config(['modules' => ['sprint' => 'App\\Modules\\Sprint\\SprintModule']]);
        $data = $this->setupProject();
        $data['project']->update(['modules' => ['sprint']]);

        $response = $this->postJson(
            "/api/v1/projects/{$data['project']->code}/modules",
            ['slug' => 'sprint'],
            $this->headers($data['token'])
        );

        $response->assertStatus(422);
    }

    // -- Module deactivation --

    public function test_deactivate_module_success(): void
    {
        $data = $this->setupProject();
        $data['project']->update(['modules' => ['sprint']]);

        $response = $this->deleteJson(
            "/api/v1/projects/{$data['project']->code}/modules/sprint",
            [],
            $this->headers($data['token'])
        );

        $response->assertStatus(204);
        $data['project']->refresh();
        $this->assertNotContains('sprint', $data['project']->modules);
    }

    public function test_deactivate_inactive_module_returns_404(): void
    {
        $data = $this->setupProject();

        $response = $this->deleteJson(
            "/api/v1/projects/{$data['project']->code}/modules/sprint",
            [],
            $this->headers($data['token'])
        );

        $response->assertStatus(404);
    }

    // -- Data retention --

    public function test_data_retained_after_module_deactivation(): void
    {
        $data = $this->setupProject();
        $data['project']->update(['modules' => ['sprint']]);

        $this->postJson(
            "/api/v1/projects/{$data['project']->code}/epics",
            ['titre' => 'Test Epic'],
            $this->headers($data['token'])
        )->assertStatus(201);

        $this->deleteJson(
            "/api/v1/projects/{$data['project']->code}/modules/sprint",
            [],
            $this->headers($data['token'])
        );

        $this->assertDatabaseHas('epics', [
            'project_id' => $data['project']->id,
            'titre' => 'Test Epic',
        ]);
    }

    // -- Ownership restriction --

    public function test_only_owners_can_activate_modules(): void
    {
        config(['modules' => ['sprint' => 'App\\Modules\\Sprint\\SprintModule']]);
        $data = $this->setupProject();

        $member = User::factory()->create();
        $memberRaw = ApiToken::generateRaw();
        $member->apiTokens()->create(['name' => 'test', 'token' => $memberRaw['hash']]);
        ProjectMember::create([
            'project_id' => $data['project']->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $response = $this->postJson(
            "/api/v1/projects/{$data['project']->code}/modules",
            ['slug' => 'sprint'],
            $this->headers($memberRaw['raw'])
        );

        $response->assertStatus(403);
    }

    // -- Pagination beyond last page --

    public function test_pagination_beyond_results_returns_empty_data(): void
    {
        $data = $this->setupProject();

        $response = $this->getJson(
            "/api/v1/projects/{$data['project']->code}/epics?page=999",
            $this->headers($data['token'])
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.current_page', 999);
        $response->assertJsonPath('meta.total', 0);
    }
}
