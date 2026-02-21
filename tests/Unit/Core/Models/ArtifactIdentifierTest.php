<?php

namespace Tests\Unit\Core\Models;

use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtifactIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['code' => 'PROJ']);
    }

    public function test_epic_gets_artifact_on_creation(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);

        $this->assertNotNull($epic->artifact);
        $this->assertEquals('PROJ-1', $epic->identifier);
    }

    public function test_sequence_is_shared_across_types(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $task = Task::factory()->create(['project_id' => $this->project->id, 'story_id' => $story->id]);

        $this->assertEquals('PROJ-1', $epic->identifier);
        $this->assertEquals('PROJ-2', $story->identifier);
        $this->assertEquals('PROJ-3', $task->identifier);
    }

    public function test_standalone_task_gets_artifact(): void
    {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id]);

        $this->assertNotNull($task->artifact);
        $this->assertEquals('PROJ-1', $task->identifier);
    }

    public function test_sequence_is_per_project(): void
    {
        $project2 = Project::factory()->create(['code' => 'OTHER']);

        Epic::factory()->create(['project_id' => $this->project->id]);
        Epic::factory()->create(['project_id' => $project2->id]);

        $this->assertEquals(1, Artifact::where('project_id', $this->project->id)->max('sequence_number'));
        $this->assertEquals(1, Artifact::where('project_id', $project2->id)->max('sequence_number'));
    }

    public function test_resolve_identifier_returns_correct_model(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);

        $resolved = Artifact::resolveIdentifier('PROJ-1');
        $this->assertInstanceOf(Epic::class, $resolved);
        $this->assertEquals($epic->id, $resolved->id);
    }

    public function test_resolve_identifier_returns_null_for_unknown(): void
    {
        $this->assertNull(Artifact::resolveIdentifier('UNKNOWN-999'));
    }

    public function test_search_in_project(): void
    {
        $epic = Epic::factory()->create([
            'project_id' => $this->project->id,
            'titre' => 'Authentication epic',
        ]);
        Epic::factory()->create([
            'project_id' => $this->project->id,
            'titre' => 'Unrelated epic',
        ]);

        $results = Artifact::searchInProject($this->project, 'Authentication');
        $this->assertCount(1, $results);
        $this->assertEquals($epic->id, $results->first()->id);
    }
}
