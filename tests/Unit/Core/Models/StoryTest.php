<?php

namespace Tests\Unit\Core\Models;

use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Epic $epic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['code' => 'TST']);
        $this->epic = Epic::factory()->create(['project_id' => $this->project->id]);
    }

    public function test_belongs_to_epic(): void
    {
        $story = Story::factory()->create(['epic_id' => $this->epic->id]);
        $this->assertEquals($this->epic->id, $story->epic->id);
    }

    public function test_has_many_tasks(): void
    {
        $story = Story::factory()->create(['epic_id' => $this->epic->id]);
        Task::factory()->create(['project_id' => $this->project->id, 'story_id' => $story->id]);

        $this->assertCount(1, $story->tasks);
    }

    public function test_tags_cast_to_array(): void
    {
        $story = Story::factory()->create([
            'epic_id' => $this->epic->id,
            'tags' => ['api', 'urgent'],
        ]);

        $this->assertIsArray($story->tags);
        $this->assertEquals(['api', 'urgent'], $story->tags);
    }

    public function test_transition_draft_to_open(): void
    {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'draft']);
        $story->transitionStatus('open');
        $this->assertEquals('open', $story->fresh()->statut);
    }

    public function test_transition_open_to_closed(): void
    {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'open']);
        $story->transitionStatus('closed');
        $this->assertEquals('closed', $story->fresh()->statut);
    }

    public function test_transition_closed_to_open(): void
    {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'closed']);
        $story->transitionStatus('open');
        $this->assertEquals('open', $story->fresh()->statut);
    }

    public function test_transition_open_to_draft_is_forbidden(): void
    {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'open']);

        $this->expectException(ValidationException::class);
        $story->transitionStatus('draft');
    }

    public function test_filter_by_type(): void
    {
        Story::factory()->create(['epic_id' => $this->epic->id, 'type' => 'backend']);
        Story::factory()->create(['epic_id' => $this->epic->id, 'type' => 'frontend']);

        $results = Story::filter(['type' => 'backend'])->get();
        $this->assertCount(1, $results);
        $this->assertEquals('backend', $results->first()->type);
    }

    public function test_filter_by_text_search(): void
    {
        Story::factory()->create(['epic_id' => $this->epic->id, 'titre' => 'Implement login']);
        Story::factory()->create(['epic_id' => $this->epic->id, 'titre' => 'Deploy app']);

        $results = Story::filter(['q' => 'login'])->get();
        $this->assertCount(1, $results);
    }

    public function test_filter_by_tags(): void
    {
        Story::factory()->create(['epic_id' => $this->epic->id, 'tags' => ['api', 'v2']]);
        Story::factory()->create(['epic_id' => $this->epic->id, 'tags' => ['ui']]);

        $results = Story::filter(['tags' => 'api'])->get();
        $this->assertCount(1, $results);
    }
}
