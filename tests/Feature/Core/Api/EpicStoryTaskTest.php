<?php

namespace Tests\Feature\Core\Api;

use App\Core\Models\ApiToken;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Services\DependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpicStoryTaskTest extends TestCase
{
    use RefreshDatabase;

    private function createSetup(): array
    {
        $user = User::factory()->create();
        $raw = ApiToken::generateRaw();
        $user->apiTokens()->create(['name' => 'test', 'token' => $raw['hash']]);
        $project = Project::factory()->create(['code' => 'TST']);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return ['user' => $user, 'token' => $raw['raw'], 'project' => $project];
    }

    private function h(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    // 1. Create epic — identifier assigned automatically
    public function test_create_epic_assigns_identifier(): void
    {
        $s = $this->createSetup();

        $response = $this->postJson('/api/v1/projects/TST/epics', [
            'titre' => 'My Epic',
        ], $this->h($s['token']));

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertStringStartsWith('TST-', $data['identifier']);
    }

    // 2. Delete epic — cascades to stories and tasks
    public function test_delete_epic_cascades(): void
    {
        $s = $this->createSetup();

        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $task = Task::factory()->create([
            'project_id' => $s['project']->id,
            'story_id' => $story->id,
        ]);

        $identifier = $epic->fresh()->identifier;

        $this->deleteJson("/api/v1/projects/TST/epics/{$identifier}", [], $this->h($s['token']))
            ->assertStatus(204);

        $this->assertDatabaseMissing('epics', ['id' => $epic->id]);
        $this->assertDatabaseMissing('stories', ['id' => $story->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    // 3. Create story — default status is draft
    public function test_create_story_default_draft(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $epicId = $epic->fresh()->identifier;

        $response = $this->postJson("/api/v1/projects/TST/epics/{$epicId}/stories", [
            'titre' => 'My Story',
            'type' => 'backend',
        ], $this->h($s['token']));

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('draft', $data['statut']);
        $this->assertStringStartsWith('TST-', $data['identifier']);
    }

    // 4. Update story — type is immutable
    public function test_update_story_type_immutable(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id, 'type' => 'backend']);
        $storyId = $story->fresh()->identifier;

        $this->patchJson("/api/v1/projects/TST/stories/{$storyId}", [
            'type' => 'frontend',
            'titre' => 'Updated',
        ], $this->h($s['token']))->assertStatus(200);

        $story->refresh();
        $this->assertEquals('backend', $story->type);
        $this->assertEquals('Updated', $story->titre);
    }

    // 5. Filter stories
    public function test_filter_stories_by_type(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        Story::factory()->create(['epic_id' => $epic->id, 'type' => 'backend']);
        Story::factory()->create(['epic_id' => $epic->id, 'type' => 'frontend']);

        $response = $this->getJson('/api/v1/projects/TST/stories?type=backend', $this->h($s['token']));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    // 6. Story status transition — valid
    public function test_story_valid_transition(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'draft']);
        $storyId = $story->fresh()->identifier;

        $response = $this->patchJson("/api/v1/projects/TST/stories/{$storyId}/status", [
            'statut' => 'open',
        ], $this->h($s['token']));

        $response->assertStatus(200);
        $this->assertEquals('open', $response->json('data.statut'));
    }

    // 7. Story status transition — invalid
    public function test_story_invalid_transition(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
        $storyId = $story->fresh()->identifier;

        $this->patchJson("/api/v1/projects/TST/stories/{$storyId}/status", [
            'statut' => 'draft',
        ], $this->h($s['token']))->assertStatus(422);
    }

    // 8. Delete story — cascades to tasks
    public function test_delete_story_cascades_to_tasks(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $task = Task::factory()->create([
            'project_id' => $s['project']->id,
            'story_id' => $story->id,
        ]);
        $storyId = $story->fresh()->identifier;

        $this->deleteJson("/api/v1/projects/TST/stories/{$storyId}", [], $this->h($s['token']))
            ->assertStatus(204);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    // 9. Create standalone task
    public function test_create_standalone_task(): void
    {
        $s = $this->createSetup();

        $response = $this->postJson('/api/v1/projects/TST/tasks', [
            'titre' => 'Standalone Bug',
            'type' => 'backend',
        ], $this->h($s['token']));

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertNull($data['story']);
        $this->assertEquals('TST', $data['project']);
    }

    // 10. Create child task — story_id immutable
    public function test_child_task_story_id_immutable(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $storyId = $story->fresh()->identifier;

        $createResp = $this->postJson("/api/v1/projects/TST/stories/{$storyId}/tasks", [
            'titre' => 'Child Task',
            'type' => 'backend',
        ], $this->h($s['token']));

        $createResp->assertStatus(201);
        $taskIdentifier = $createResp->json('data.identifier');

        // Try to update story_id — should be ignored
        $this->patchJson("/api/v1/projects/TST/tasks/{$taskIdentifier}", [
            'story_id' => null,
            'titre' => 'Updated Child',
        ], $this->h($s['token']))->assertStatus(200);

        $task = Task::whereHas('artifact', fn ($q) => $q->where('identifier', $taskIdentifier))->first();
        $this->assertNotNull($task->story_id);
        $this->assertEquals('Updated Child', $task->titre);
    }

    // 11. Batch story creation — atomic on error
    public function test_batch_story_creation_atomic(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $epicId = $epic->fresh()->identifier;

        $response = $this->postJson("/api/v1/projects/TST/epics/{$epicId}/stories/batch", [
            'stories' => [
                ['titre' => 'Valid Story', 'type' => 'backend'],
                ['titre' => '', 'type' => 'backend'], // invalid: empty titre
            ],
        ], $this->h($s['token']));

        $response->assertStatus(422);

        // No stories should have been created
        $storyCount = Story::where('epic_id', $epic->id)->count();
        $this->assertEquals(0, $storyCount);
    }

    // 12. Circular dependency rejected
    public function test_circular_dependency_rejected(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $storyA = Story::factory()->create(['epic_id' => $epic->id]);
        $storyB = Story::factory()->create(['epic_id' => $epic->id]);
        $idA = $storyA->fresh()->identifier;
        $idB = $storyB->fresh()->identifier;

        // A blocked_by B
        $this->postJson('/api/v1/dependencies', [
            'blocked_identifier' => $idA,
            'blocking_identifier' => $idB,
        ], $this->h($s['token']))->assertStatus(201);

        // B blocked_by A — should be rejected (circular)
        $this->postJson('/api/v1/dependencies', [
            'blocked_identifier' => $idB,
            'blocking_identifier' => $idA,
        ], $this->h($s['token']))->assertStatus(422);
    }

    // 13. Delete item with dependencies — cleaned up
    public function test_delete_item_cleans_dependencies(): void
    {
        $s = $this->createSetup();
        $epic = Epic::factory()->create(['project_id' => $s['project']->id]);
        $storyA = Story::factory()->create(['epic_id' => $epic->id]);
        $storyB = Story::factory()->create(['epic_id' => $epic->id]);

        $service = app(DependencyService::class);
        $service->addDependency($storyA, $storyB);

        $idA = $storyA->fresh()->identifier;
        $this->deleteJson("/api/v1/projects/TST/stories/{$idA}", [], $this->h($s['token']))
            ->assertStatus(204);

        $this->assertDatabaseMissing('item_dependencies', [
            'item_id' => $storyA->id,
        ]);
    }
}
