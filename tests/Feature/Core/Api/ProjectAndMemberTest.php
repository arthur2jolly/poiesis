<?php

namespace Tests\Feature\Core\Api;

use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAndMemberTest extends TestCase
{
    use RefreshDatabase;

    private function createAuth(): array
    {
        $user = User::factory()->create();
        $raw = ApiToken::generateRaw();
        $user->apiTokens()->create(['name' => 'test', 'token' => $raw['hash']]);

        return ['user' => $user, 'token' => $raw['raw']];
    }

    private function h(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function setupProject(array $auth, array $projectAttrs = []): Project
    {
        $project = Project::factory()->create($projectAttrs);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $auth['user']->id,
            'role' => 'owner',
        ]);

        return $project;
    }

    public function test_create_project_returns_201(): void
    {
        $auth = $this->createAuth();

        $response = $this->postJson('/api/v1/projects', [
            'code' => 'MYPROJ-1',
            'titre' => 'Mon Projet',
        ], $this->h($auth['token']));

        $response->assertStatus(201);
        $response->assertJsonFragment(['code' => 'MYPROJ-1', 'titre' => 'Mon Projet']);
    }

    public function test_create_project_fails_with_duplicate_code(): void
    {
        $auth = $this->createAuth();
        Project::factory()->create(['code' => 'DUP-CODE']);

        $this->postJson('/api/v1/projects', [
            'code' => 'DUP-CODE',
            'titre' => 'Duplicate',
        ], $this->h($auth['token']))->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_create_project_fails_with_invalid_code(): void
    {
        $auth = $this->createAuth();

        $this->postJson('/api/v1/projects', [
            'code' => 'a',
            'titre' => 'Invalid Code',
        ], $this->h($auth['token']))->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_creator_becomes_owner(): void
    {
        $auth = $this->createAuth();

        $this->postJson('/api/v1/projects', [
            'code' => 'OWNTEST',
            'titre' => 'Owner Test',
        ], $this->h($auth['token']))->assertStatus(201);

        $project = Project::where('code', 'OWNTEST')->first();
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $auth['user']->id,
            'role' => 'owner',
        ]);
    }

    public function test_list_projects_filtered_by_membership(): void
    {
        $auth = $this->createAuth();
        $this->setupProject($auth, ['code' => 'MINE']);
        Project::factory()->create(['code' => 'OTHER']);

        $response = $this->getJson('/api/v1/projects', $this->h($auth['token']));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['code' => 'MINE']);
    }

    public function test_update_project_code_unchanged(): void
    {
        $auth = $this->createAuth();
        $project = $this->setupProject($auth, ['code' => 'STABLE']);

        $this->patchJson('/api/v1/projects/STABLE', [
            'code' => 'CHANGED',
            'titre' => 'Updated Title',
        ], $this->h($auth['token']))->assertStatus(200);

        $project->refresh();
        $this->assertEquals('STABLE', $project->code);
        $this->assertEquals('Updated Title', $project->titre);
    }

    public function test_delete_project_forbidden_for_member(): void
    {
        $owner = $this->createAuth();
        $this->setupProject($owner, ['code' => 'DELTEST']);

        $member = $this->createAuth();
        $project = Project::where('code', 'DELTEST')->first();
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member['user']->id,
            'role' => 'member',
        ]);

        $this->deleteJson('/api/v1/projects/DELTEST', [], $this->h($member['token']))
            ->assertStatus(403);
    }

    public function test_add_member_success(): void
    {
        $auth = $this->createAuth();
        $project = $this->setupProject($auth, ['code' => 'ADDMEM']);
        $newUser = User::factory()->create();

        $response = $this->postJson('/api/v1/projects/ADDMEM/members', [
            'user_id' => $newUser->id,
            'role' => 'member',
        ], $this->h($auth['token']));

        $response->assertStatus(201);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $newUser->id,
            'role' => 'member',
        ]);
    }

    public function test_add_member_duplicate_rejected(): void
    {
        $auth = $this->createAuth();
        $project = $this->setupProject($auth, ['code' => 'DUPMEM']);
        $newUser = User::factory()->create();
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $newUser->id,
            'role' => 'member',
        ]);

        $this->postJson('/api/v1/projects/DUPMEM/members', [
            'user_id' => $newUser->id,
            'role' => 'member',
        ], $this->h($auth['token']))->assertStatus(422);
    }

    public function test_remove_last_owner_rejected(): void
    {
        $auth = $this->createAuth();
        $project = $this->setupProject($auth, ['code' => 'LASTOW']);

        // Use the store endpoint to get a proper member, then find the owner
        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $auth['user']->id)
            ->first();

        $this->assertNotNull($member->id, 'Member ID should not be null');

        $this->deleteJson("/api/v1/projects/LASTOW/members/{$member->id}", [], $this->h($auth['token']))
            ->assertStatus(422);
    }

    public function test_downgrade_last_owner_rejected(): void
    {
        $auth = $this->createAuth();
        $project = $this->setupProject($auth, ['code' => 'DWNOWN']);

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $auth['user']->id)
            ->first();

        $this->assertNotNull($member->id, 'Member ID should not be null');

        $this->patchJson("/api/v1/projects/DWNOWN/members/{$member->id}", [
            'role' => 'member',
        ], $this->h($auth['token']))->assertStatus(422);

        $member->refresh();
        $this->assertEquals('owner', $member->role);
    }
}
