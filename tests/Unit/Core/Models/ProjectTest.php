<?php

namespace Tests\Unit\Core\Models;

use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_key_name_is_code(): void
    {
        $this->assertEquals('code', (new Project)->getRouteKeyName());
    }

    public function test_modules_cast_to_array(): void
    {
        $tenant = createTenant();
        $project = Project::factory()->create(['modules' => ['sprint', 'comments'], 'tenant_id' => $tenant->id]);
        $this->assertIsArray($project->modules);
        $this->assertEquals(['sprint', 'comments'], $project->modules);
    }

    public function test_has_many_epics(): void
    {
        $tenant = createTenant();
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        Epic::factory()->create(['project_id' => $project->id]);

        $this->assertCount(1, $project->epics);
    }

    public function test_has_many_standalone_tasks(): void
    {
        $tenant = createTenant();
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);

        Task::factory()->standalone()->create(['project_id' => $project->id]);
        Task::factory()->create(['project_id' => $project->id, 'story_id' => $story->id]);

        $this->assertCount(1, $project->standaloneTasks);
        $this->assertCount(2, $project->tasks);
    }

    public function test_accessible_by_scope(): void
    {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        $other = Project::factory()->create(['tenant_id' => $tenant->id]);

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'member']);

        $accessible = Project::accessibleBy($user)->get();
        $this->assertCount(1, $accessible);
        $this->assertEquals($project->id, $accessible->first()->id);
    }

    public function test_belongs_to_many_users(): void
    {
        $tenant = createTenant();
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'owner']);

        $this->assertCount(1, $project->users);
        $this->assertEquals('owner', $project->users->first()->pivot->position);
    }
}
