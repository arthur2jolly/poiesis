<?php

namespace Tests\Unit\Core\Services;

use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Services\DependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DependencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private DependencyService $service;

    private Project $project;

    private Epic $epic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DependencyService;
        $this->project = Project::factory()->create(['code' => 'DEP']);
        $this->epic = Epic::factory()->create(['project_id' => $this->project->id]);
    }

    public function test_add_dependency_between_stories(): void
    {
        $s1 = Story::factory()->create(['epic_id' => $this->epic->id]);
        $s2 = Story::factory()->create(['epic_id' => $this->epic->id]);

        $this->service->addDependency($s2, $s1);

        $deps = $this->service->getDependencies($s2);
        $this->assertCount(1, $deps['blocked_by']);
        $this->assertEquals($s1->id, $deps['blocked_by'][0]->id);

        $deps = $this->service->getDependencies($s1);
        $this->assertCount(1, $deps['blocks']);
        $this->assertEquals($s2->id, $deps['blocks'][0]->id);
    }

    public function test_add_cross_type_dependency(): void
    {
        $story = Story::factory()->create(['epic_id' => $this->epic->id]);
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id]);

        $this->service->addDependency($story, $task);

        $deps = $this->service->getDependencies($story);
        $this->assertCount(1, $deps['blocked_by']);
        $this->assertInstanceOf(Task::class, $deps['blocked_by'][0]);
    }

    public function test_cannot_depend_on_self(): void
    {
        $story = Story::factory()->create(['epic_id' => $this->epic->id]);

        $this->expectException(ValidationException::class);
        $this->service->addDependency($story, $story);
    }

    public function test_duplicate_dependency_rejected(): void
    {
        $s1 = Story::factory()->create(['epic_id' => $this->epic->id]);
        $s2 = Story::factory()->create(['epic_id' => $this->epic->id]);

        $this->service->addDependency($s2, $s1);

        $this->expectException(ValidationException::class);
        $this->service->addDependency($s2, $s1);
    }

    public function test_direct_circular_dependency_rejected(): void
    {
        $s1 = Story::factory()->create(['epic_id' => $this->epic->id]);
        $s2 = Story::factory()->create(['epic_id' => $this->epic->id]);

        $this->service->addDependency($s2, $s1); // s2 blocked by s1

        $this->expectException(ValidationException::class);
        $this->service->addDependency($s1, $s2); // s1 blocked by s2 => circular
    }

    public function test_transitive_circular_dependency_rejected(): void
    {
        $a = Story::factory()->create(['epic_id' => $this->epic->id]);
        $b = Story::factory()->create(['epic_id' => $this->epic->id]);
        $c = Story::factory()->create(['epic_id' => $this->epic->id]);

        $this->service->addDependency($b, $a); // B blocked by A
        $this->service->addDependency($c, $b); // C blocked by B

        $this->expectException(ValidationException::class);
        $this->service->addDependency($a, $c); // A blocked by C => A->C->B->A circular
    }

    public function test_remove_dependency(): void
    {
        $s1 = Story::factory()->create(['epic_id' => $this->epic->id]);
        $s2 = Story::factory()->create(['epic_id' => $this->epic->id]);

        $this->service->addDependency($s2, $s1);
        $this->service->removeDependency($s2, $s1);

        $deps = $this->service->getDependencies($s2);
        $this->assertCount(0, $deps['blocked_by']);
    }

    public function test_get_dependencies_empty(): void
    {
        $story = Story::factory()->create(['epic_id' => $this->epic->id]);

        $deps = $this->service->getDependencies($story);
        $this->assertCount(0, $deps['blocked_by']);
        $this->assertCount(0, $deps['blocks']);
    }
}
