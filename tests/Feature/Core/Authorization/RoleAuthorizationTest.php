<?php

namespace Tests\Feature\Core\Authorization;

use App\Core\Models\ApiToken;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $adminUser;

    private User $managerUser;

    private User $developerUser;

    private User $viewerUser;

    private User $outsiderUser;

    private User $outsiderDeveloper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users with different roles
        $this->adminUser = User::factory()->administrator()->create(['name' => 'Admin']);
        $this->managerUser = User::factory()->manager()->create(['name' => 'Manager']);
        $this->developerUser = User::factory()->developer()->create(['name' => 'Developer']);
        $this->viewerUser = User::factory()->viewer()->create(['name' => 'Viewer']);
        $this->outsiderUser = User::factory()->viewer()->create(['name' => 'Outsider']);
        $this->outsiderDeveloper = User::factory()->developer()->create(['name' => 'OutsiderDev']);

        // Create a project and add members
        $this->project = Project::factory()->create(['code' => 'AUTH']);

        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->adminUser->id,
            'role' => 'owner',
        ]);

        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->managerUser->id,
            'role' => 'member',
        ]);

        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->developerUser->id,
            'role' => 'member',
        ]);

        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->viewerUser->id,
            'role' => 'member',
        ]);
    }

    private function mcp(string $method, User $user, array $params = []): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::generateRaw();
        $user->apiTokens()->create(['name' => 'test', 'token' => $token['hash']]);

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => $method,
            'params' => $params,
        ], ['Authorization' => 'Bearer '.$token['raw']]);
    }

    private function mcpCall(string $toolName, array $arguments, User $user): \Illuminate\Testing\TestResponse
    {
        return $this->mcp('tools/call', $user, [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);
    }

    // ───────────────────────────────────────────────────────────────
    // PROJECT CREATION TESTS — Only Admin/Manager can create
    // ───────────────────────────────────────────────────────────────

    public function test_administrator_can_create_project(): void
    {
        $response = $this->mcpCall('create_project', [
            'code' => 'ADMIN1',
            'titre' => 'Admin Project',
        ], $this->adminUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);
    }

    public function test_manager_can_create_project(): void
    {
        $response = $this->mcpCall('create_project', [
            'code' => 'MNGR1',
            'titre' => 'Manager Project',
        ], $this->managerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);
    }

    public function test_developer_cannot_create_project(): void
    {
        $response = $this->mcpCall('create_project', [
            'code' => 'DEV1',
            'titre' => 'Dev Project',
        ], $this->developerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('You do not have permission', $data['error']['message']);
    }

    public function test_viewer_cannot_create_project(): void
    {
        $response = $this->mcpCall('create_project', [
            'code' => 'VIEW1',
            'titre' => 'Viewer Project',
        ], $this->viewerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('You do not have permission', $data['error']['message']);
    }

    // ───────────────────────────────────────────────────────────────
    // ARTIFACT CREATION TESTS — Admin/Manager/Developer can create
    // ───────────────────────────────────────────────────────────────

    public function test_administrator_can_create_epic(): void
    {
        $response = $this->mcpCall('create_epic', [
            'project_code' => 'AUTH',
            'titre' => 'Admin Epic',
        ], $this->adminUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);
    }

    public function test_manager_can_create_epic(): void
    {
        $response = $this->mcpCall('create_epic', [
            'project_code' => 'AUTH',
            'titre' => 'Manager Epic',
        ], $this->managerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);
    }

    public function test_developer_can_create_epic(): void
    {
        $response = $this->mcpCall('create_epic', [
            'project_code' => 'AUTH',
            'titre' => 'Developer Epic',
        ], $this->developerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);
    }

    public function test_viewer_cannot_create_epic(): void
    {
        $response = $this->mcpCall('create_epic', [
            'project_code' => 'AUTH',
            'titre' => 'Viewer Epic',
        ], $this->viewerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('You do not have permission', $data['error']['message']);
    }

    // ───────────────────────────────────────────────────────────────
    // PROJECT ACCESS CONTROL — Non-members get 403
    // ───────────────────────────────────────────────────────────────

    public function test_non_member_cannot_create_epic(): void
    {
        $response = $this->mcpCall('create_epic', [
            'project_code' => 'AUTH',
            'titre' => 'Unauthorized Epic',
        ], $this->outsiderDeveloper);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Access denied', $data['error']['message']);
    }

    public function test_non_member_cannot_read_artifact(): void
    {
        $epic = Epic::factory()->for($this->project)->create(['titre' => 'Test Epic']);

        $response = $this->mcpCall('get_epic', [
            'identifier' => $epic->identifier,
        ], $this->outsiderUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Access denied', $data['error']['message']);
    }

    // ───────────────────────────────────────────────────────────────
    // ARTIFACT UPDATE/DELETE TESTS — Role-based
    // ───────────────────────────────────────────────────────────────

    public function test_developer_can_update_epic(): void
    {
        $epic = Epic::factory()->for($this->project)->create(['titre' => 'Original']);

        $response = $this->mcpCall('update_epic', [
            'identifier' => $epic->identifier,
            'titre' => 'Updated by Developer',
        ], $this->developerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);

        $epic->refresh();
        $this->assertEquals('Updated by Developer', $epic->titre);
    }

    public function test_developer_can_delete_epic(): void
    {
        $epic = Epic::factory()->for($this->project)->create();
        $id = $epic->id;

        $response = $this->mcpCall('delete_epic', [
            'identifier' => $epic->identifier,
        ], $this->developerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);

        $this->assertDatabaseMissing('epics', ['id' => $id]);
    }

    public function test_viewer_cannot_update_epic(): void
    {
        $epic = Epic::factory()->for($this->project)->create();

        $response = $this->mcpCall('update_epic', [
            'identifier' => $epic->identifier,
            'titre' => 'Updated by Viewer',
        ], $this->viewerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('You do not have permission', $data['error']['message']);
    }

    public function test_viewer_cannot_delete_epic(): void
    {
        $epic = Epic::factory()->for($this->project)->create();

        $response = $this->mcpCall('delete_epic', [
            'identifier' => $epic->identifier,
        ], $this->viewerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('You do not have permission', $data['error']['message']);
    }

    // ───────────────────────────────────────────────────────────────
    // STORY CRUD TESTS
    // ───────────────────────────────────────────────────────────────

    public function test_manager_can_create_story(): void
    {
        $epic = Epic::factory()->for($this->project)->create();

        $response = $this->mcpCall('create_story', [
            'project_code' => 'AUTH',
            'epic_identifier' => $epic->identifier,
            'titre' => 'Manager Story',
            'type' => 'backend',
        ], $this->managerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);
    }

    public function test_developer_can_create_story(): void
    {
        $epic = Epic::factory()->for($this->project)->create();

        $response = $this->mcpCall('create_story', [
            'project_code' => 'AUTH',
            'epic_identifier' => $epic->identifier,
            'titre' => 'Developer Story',
            'type' => 'backend',
        ], $this->developerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);
    }

    public function test_viewer_cannot_create_story(): void
    {
        $epic = Epic::factory()->for($this->project)->create();

        $response = $this->mcpCall('create_story', [
            'project_code' => 'AUTH',
            'epic_identifier' => $epic->identifier,
            'titre' => 'Viewer Story',
            'type' => 'backend',
        ], $this->viewerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('You do not have permission', $data['error']['message']);
    }

    // ───────────────────────────────────────────────────────────────
    // TASK CRUD TESTS
    // ───────────────────────────────────────────────────────────────

    public function test_developer_can_create_task(): void
    {
        $response = $this->mcpCall('create_task', [
            'project_code' => 'AUTH',
            'titre' => 'Dev Task',
            'type' => 'backend',
        ], $this->developerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);
    }

    public function test_viewer_cannot_create_task(): void
    {
        $response = $this->mcpCall('create_task', [
            'project_code' => 'AUTH',
            'titre' => 'Viewer Task',
            'type' => 'backend',
        ], $this->viewerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('You do not have permission', $data['error']['message']);
    }

    public function test_developer_can_update_task_status(): void
    {
        $task = Task::factory()->for($this->project)->create(['statut' => 'draft']);

        $response = $this->mcpCall('update_task_status', [
            'identifier' => $task->identifier,
            'statut' => 'open',
        ], $this->developerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayNotHasKey('error', $data);
    }

    public function test_viewer_cannot_update_task_status(): void
    {
        $task = Task::factory()->for($this->project)->create(['statut' => 'draft']);

        $response = $this->mcpCall('update_task_status', [
            'identifier' => $task->identifier,
            'statut' => 'open',
        ], $this->viewerUser);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('You do not have permission', $data['error']['message']);
    }

    // ───────────────────────────────────────────────────────────────
    // ROLE HIERARCHY TESTS
    // ───────────────────────────────────────────────────────────────

    public function test_administrator_role_has_all_permissions(): void
    {
        $this->assertTrue(Role::canCrudProjects(Role::ADMINISTRATOR));
        $this->assertTrue(Role::canCrudArtifacts(Role::ADMINISTRATOR));
        $this->assertTrue(Role::canManageTokens(Role::ADMINISTRATOR));
        $this->assertTrue(Role::canManageUsers(Role::ADMINISTRATOR));
    }

    public function test_manager_role_can_crud_projects_and_artifacts(): void
    {
        $this->assertTrue(Role::canCrudProjects(Role::MANAGER));
        $this->assertTrue(Role::canCrudArtifacts(Role::MANAGER));
        $this->assertTrue(Role::canManageTokens(Role::MANAGER));
        $this->assertFalse(Role::canManageUsers(Role::MANAGER));
    }

    public function test_developer_role_can_crud_artifacts_only(): void
    {
        $this->assertFalse(Role::canCrudProjects(Role::DEVELOPER));
        $this->assertTrue(Role::canCrudArtifacts(Role::DEVELOPER));
        $this->assertFalse(Role::canManageTokens(Role::DEVELOPER));
        $this->assertFalse(Role::canManageUsers(Role::DEVELOPER));
    }

    public function test_viewer_role_has_no_permissions(): void
    {
        $this->assertFalse(Role::canCrudProjects(Role::VIEWER));
        $this->assertFalse(Role::canCrudArtifacts(Role::VIEWER));
        $this->assertFalse(Role::canManageTokens(Role::VIEWER));
        $this->assertFalse(Role::canManageUsers(Role::VIEWER));
    }
}
