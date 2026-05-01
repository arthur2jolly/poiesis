<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Scrum;

use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\Tenant;
use App\Core\Services\TenantManager;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SprintItemModelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Sprint $sprint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = createTenant();

        app(TenantManager::class)->setTenant($this->tenant);

        $this->project = Project::factory()->create([
            'code' => 'POI',
            'tenant_id' => $this->tenant->id,
        ]);

        $this->sprint = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint 1',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);
    }

    public function test_sprint_item_create_with_artifact_id(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);

        // $story->artifact is populated via HasArtifactIdentifier after creation
        $artifact = $story->artifact;
        $this->assertNotNull($artifact);

        $item = SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $artifact->id,
            'position' => 0,
        ]);

        $this->assertNotNull($item->id);

        // added_at is set by DB DEFAULT CURRENT_TIMESTAMP — refresh to read it
        $item->refresh();
        $this->assertNotNull($item->added_at);
    }

    public function test_sprint_item_has_no_timestamps(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);

        $item = SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $story->artifact->id,
            'position' => 0,
        ]);

        $this->assertNull($item->created_at ?? null);
        $this->assertNull($item->updated_at ?? null);
        $this->assertFalse($item->timestamps);
    }

    public function test_unique_artifact_id_constraint(): void
    {
        $s2 = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint 2',
            'start_date' => '2026-05-15',
            'end_date' => '2026-05-28',
        ]);

        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $artifactId = $story->artifact->id;

        SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $artifactId,
            'position' => 0,
        ]);

        $this->expectException(QueryException::class);

        SprintItem::create([
            'sprint_id' => $s2->id,
            'artifact_id' => $artifactId,
            'position' => 0,
        ]);
    }

    public function test_sprint_cascade_delete_removes_items(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);

        $item = SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $story->artifact->id,
            'position' => 0,
        ]);

        $itemId = $item->id;

        $this->sprint->delete();

        $this->assertNull(SprintItem::find($itemId));
    }

    public function test_artifact_cascade_delete_removes_items_silently(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);

        $item = SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $story->artifact->id,
            'position' => 0,
        ]);

        $itemId = $item->id;

        // Deleting the story triggers HasArtifactIdentifier boot hook → artifact deleted → sprint_item cascade
        $story->delete();

        $this->assertNull(SprintItem::find($itemId));
    }

    public function test_sprint_relation_loads_items_ordered_by_position(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $s1 = Story::factory()->create(['epic_id' => $epic->id]);
        $s2 = Story::factory()->create(['epic_id' => $epic->id]);
        $s3 = Story::factory()->create(['epic_id' => $epic->id]);

        SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $s1->artifact->id,
            'position' => 2,
        ]);

        SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $s2->artifact->id,
            'position' => 0,
        ]);

        SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $s3->artifact->id,
            'position' => 1,
        ]);

        $positions = $this->sprint->items->pluck('position')->all();

        $this->assertEquals([0, 1, 2], $positions);
    }

    public function test_artifact_relation_resolves(): void
    {
        $task = Task::factory()->standalone()->create([
            'project_id' => $this->project->id,
        ]);

        $item = SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $task->artifact->id,
            'position' => 0,
        ]);

        $this->assertEquals($task->id, $item->artifact->artifactable_id);
    }
}
