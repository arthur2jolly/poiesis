<?php

namespace Tests\Unit\Core\Models;

use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['code' => 'TSK']);
    }

    public function test_standalone_task(): void
    {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id]);
        $this->assertTrue($task->isStandalone());
        $this->assertNull($task->story_id);
    }

    public function test_child_task(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $task = Task::factory()->create(['project_id' => $this->project->id, 'story_id' => $story->id]);

        $this->assertFalse($task->isStandalone());
        $this->assertEquals($story->id, $task->story->id);
    }

    public function test_transition_status(): void
    {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id, 'statut' => 'draft']);
        $task->transitionStatus('open');
        $this->assertEquals('open', $task->fresh()->statut);

        $task->transitionStatus('closed');
        $this->assertEquals('closed', $task->fresh()->statut);

        $task->transitionStatus('open');
        $this->assertEquals('open', $task->fresh()->statut);
    }

    public function test_invalid_transition_throws(): void
    {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id, 'statut' => 'closed']);

        $this->expectException(ValidationException::class);
        $task->transitionStatus('draft');
    }

    public function test_filter_scope(): void
    {
        Task::factory()->standalone()->create(['project_id' => $this->project->id, 'type' => 'qa', 'priorite' => 'critique']);
        Task::factory()->standalone()->create(['project_id' => $this->project->id, 'type' => 'backend', 'priorite' => 'basse']);

        $results = Task::filter(['type' => 'qa'])->get();
        $this->assertCount(1, $results);

        $results = Task::filter(['priorite' => 'critique'])->get();
        $this->assertCount(1, $results);
    }
}
