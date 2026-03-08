<?php

namespace Tests\Unit\Core\Models;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_last_owner_returns_true_when_single_owner(): void
    {
        $tenant = createTenant();
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'owner']);

        $this->assertTrue(ProjectMember::isLastOwner($project->id, $user->id));
    }

    public function test_is_last_owner_returns_false_when_multiple_owners(): void
    {
        $tenant = createTenant();
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        $u1 = User::factory()->create(['tenant_id' => $tenant->id]);
        $u2 = User::factory()->create(['tenant_id' => $tenant->id]);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $u1->id, 'position' => 'owner']);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $u2->id, 'position' => 'owner']);

        $this->assertFalse(ProjectMember::isLastOwner($project->id, $u1->id));
    }

    public function test_is_last_owner_returns_false_for_member(): void
    {
        $tenant = createTenant();
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $owner->id, 'position' => 'owner']);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'position' => 'member']);

        $this->assertFalse(ProjectMember::isLastOwner($project->id, $member->id));
    }
}
