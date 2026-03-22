<?php

namespace Tests\Feature\Core\Api;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAndMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_project_returns_201(): void
    {
        $auth = createAuth();

        $response = $this->postJson('/api/v1/projects', [
            'code' => 'MYPROJ-1',
            'titre' => 'Mon Projet',
        ], authHeader($auth['token']));

        $response->assertStatus(201);
        $response->assertJsonFragment(['code' => 'MYPROJ-1', 'titre' => 'Mon Projet']);
    }

    public function test_create_project_fails_with_duplicate_code(): void
    {
        $auth = createAuth();
        Project::factory()->create(['code' => 'DUP-CODE', 'tenant_id' => $auth['tenant']->id]);

        $this->postJson('/api/v1/projects', [
            'code' => 'DUP-CODE',
            'titre' => 'Duplicate',
        ], authHeader($auth['token']))->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_create_project_fails_with_invalid_code(): void
    {
        $auth = createAuth();

        $this->postJson('/api/v1/projects', [
            'code' => 'a',
            'titre' => 'Invalid Code',
        ], authHeader($auth['token']))->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_creator_becomes_owner(): void
    {
        $auth = createAuth();

        $this->postJson('/api/v1/projects', [
            'code' => 'OWNTEST',
            'titre' => 'Owner Test',
        ], authHeader($auth['token']))->assertStatus(201);

        $project = Project::where('code', 'OWNTEST')->first();
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $auth['user']->id,
            'position' => 'owner',
        ]);
    }

    public function test_list_projects_filtered_by_membership(): void
    {
        $auth = createAuth();
        setupProject($auth, ['code' => 'MINE']);
        // Project in a different tenant — not visible to this user
        $otherTenant = createTenant();
        Project::factory()->create(['code' => 'OTHER', 'tenant_id' => $otherTenant->id]);

        $response = $this->getJson('/api/v1/projects', authHeader($auth['token']));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['code' => 'MINE']);
    }

    public function test_update_project_code_unchanged(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'STABLE']);

        $this->patchJson('/api/v1/projects/STABLE', [
            'code' => 'CHANGED',
            'titre' => 'Updated Title',
        ], authHeader($auth['token']))->assertStatus(200);

        $project->refresh();
        $this->assertEquals('STABLE', $project->code);
        $this->assertEquals('Updated Title', $project->titre);
    }

    public function test_delete_project_forbidden_for_member(): void
    {
        $tenant = createTenant();
        $owner = createAuth($tenant);
        setupProject($owner, ['code' => 'DELTEST']);

        $member = createAuth($tenant);
        $project = Project::where('code', 'DELTEST')->first();
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member['user']->id,
            'position' => 'member',
        ]);

        $this->deleteJson('/api/v1/projects/DELTEST', [], authHeader($member['token']))
            ->assertStatus(403);
    }

    public function test_add_member_success(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'ADDMEM']);
        $newUser = User::factory()->create(['tenant_id' => $auth['tenant']->id]);

        $response = $this->postJson('/api/v1/projects/ADDMEM/members', [
            'user_id' => $newUser->id,
            'position' => 'member',
        ], authHeader($auth['token']));

        $response->assertStatus(201);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $newUser->id,
            'position' => 'member',
        ]);
    }

    public function test_add_member_duplicate_rejected(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'DUPMEM']);
        $newUser = User::factory()->create(['tenant_id' => $auth['tenant']->id]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $newUser->id,
            'position' => 'member',
        ]);

        $this->postJson('/api/v1/projects/DUPMEM/members', [
            'user_id' => $newUser->id,
            'position' => 'member',
        ], authHeader($auth['token']))->assertStatus(422);
    }

    public function test_remove_last_owner_rejected(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'LASTOW']);

        // Use the store endpoint to get a proper member, then find the owner
        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $auth['user']->id)
            ->first();

        $this->assertNotNull($member->id, 'Member ID should not be null');

        $this->deleteJson("/api/v1/projects/LASTOW/members/{$member->id}", [], authHeader($auth['token']))
            ->assertStatus(422);
    }

    public function test_downgrade_last_owner_rejected(): void
    {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'DWNOWN']);

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $auth['user']->id)
            ->first();

        $this->assertNotNull($member->id, 'Member ID should not be null');

        $this->patchJson("/api/v1/projects/DWNOWN/members/{$member->id}", [
            'position' => 'member',
        ], authHeader($auth['token']))->assertStatus(422);

        $member->refresh();
        $this->assertEquals('owner', $member->position);
    }
}
