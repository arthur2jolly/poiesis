<?php

namespace Tests\Unit\Core\Services;

use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\Tenant;
use App\Core\Services\DependencyService;
use App\Core\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_cross_tenant_blocker_is_not_leaked_in_get_dependencies(): void
    {
        // Tenant A: a story that has a blocker pointing to a story in Tenant B.
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $projectA = Project::factory()->create(['tenant_id' => $tenantA->id, 'code' => 'TA']);
        $epicA = Epic::factory()->create(['project_id' => $projectA->id]);
        $storyA = Story::factory()->create(['epic_id' => $epicA->id]);

        $projectB = Project::factory()->create(['tenant_id' => $tenantB->id, 'code' => 'TB']);
        $epicB = Epic::factory()->create(['project_id' => $projectB->id]);
        $storyB = Story::factory()->create(['epic_id' => $epicB->id]);

        $taskB = Task::factory()->standalone()->create(['project_id' => $projectB->id]);

        // Inject the cross-tenant dependencies directly (bypassing the service's
        // own validation, simulating either historical bad data or an admin path).
        DB::table('item_dependencies')->insert([
            [
                'id' => (string) Str::uuid7(),
                'item_id' => $storyA->id,
                'item_type' => Story::class,
                'depends_on_id' => $storyB->id,
                'depends_on_type' => Story::class,
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid7(),
                'item_id' => $storyA->id,
                'item_type' => Story::class,
                'depends_on_id' => $taskB->id,
                'depends_on_type' => Task::class,
                'created_at' => now(),
            ],
        ]);

        // Act as Tenant A.
        app(TenantManager::class)->setTenant($tenantA);

        $deps = $this->service->getDependencies($storyA);

        $this->assertCount(0, $deps['blocked_by'], 'No cross-tenant blocker should leak into the response.');
        $this->assertCount(0, $deps['blocks']);

        // Symmetric isolation: from Tenant B's side, the inverse "blocks"
        // edge points to a Story owned by Tenant A and must also be filtered.
        app(TenantManager::class)->setTenant($tenantB);
        $depsFromB = $this->service->getDependencies($storyB);
        $this->assertCount(0, $depsFromB['blocks'], 'Cross-tenant inverse edge must not leak either.');
    }

    public function test_intra_tenant_blocker_still_resolves(): void
    {
        $tenant = Tenant::factory()->create();
        $project = Project::factory()->create(['tenant_id' => $tenant->id, 'code' => 'OK']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $blocker = Story::factory()->create(['epic_id' => $epic->id]);

        app(TenantManager::class)->setTenant($tenant);

        $this->service->addDependency($story, $blocker);

        $deps = $this->service->getDependencies($story);
        $this->assertCount(1, $deps['blocked_by']);
        $this->assertSame($blocker->id, $deps['blocked_by'][0]->id);
    }
}
