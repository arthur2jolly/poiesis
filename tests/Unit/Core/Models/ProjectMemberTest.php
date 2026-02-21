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
        $project = Project::factory()->create();
        $user = User::factory()->create();
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->assertTrue(ProjectMember::isLastOwner($project->id, $user->id));
    }

    public function test_is_last_owner_returns_false_when_multiple_owners(): void
    {
        $project = Project::factory()->create();
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $u1->id, 'role' => 'owner']);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $u2->id, 'role' => 'owner']);

        $this->assertFalse(ProjectMember::isLastOwner($project->id, $u1->id));
    }

    public function test_is_last_owner_returns_false_for_member(): void
    {
        $project = Project::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $owner->id, 'role' => 'owner']);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'role' => 'member']);

        $this->assertFalse(ProjectMember::isLastOwner($project->id, $member->id));
    }
}
