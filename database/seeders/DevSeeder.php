<?php

namespace Database\Seeders;

use App\Core\Models\ApiToken;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Services\DependencyService;
use Illuminate\Database\Seeder;

class DevSeeder extends Seeder
{
    public function run(): void
    {
        $depService = new DependencyService;

        foreach (['DEMO' => 'Demo Project', 'TEST' => 'Test Project'] as $code => $titre) {
            $owner = User::factory()->create(['name' => "{$code} Owner"]);
            $member = User::factory()->create(['name' => "{$code} Member"]);

            // Generate a token for the owner
            $tokenData = ApiToken::generateRaw();
            ApiToken::create([
                'user_id' => $owner->id,
                'name' => 'default',
                'token' => $tokenData['hash'],
            ]);

            $project = Project::factory()->create([
                'code' => $code,
                'titre' => $titre,
                'description' => "Seed project for {$code}",
            ]);

            ProjectMember::create(['project_id' => $project->id, 'user_id' => $owner->id, 'role' => 'owner']);
            ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'role' => 'member']);

            $allStories = [];

            for ($e = 1; $e <= 3; $e++) {
                $epic = Epic::factory()->create([
                    'project_id' => $project->id,
                    'titre' => "Epic {$e} for {$code}",
                ]);

                for ($s = 1; $s <= 5; $s++) {
                    $story = Story::factory()->create([
                        'epic_id' => $epic->id,
                        'titre' => "Story {$e}.{$s}",
                        'ordre' => $s,
                    ]);
                    $allStories[] = $story;

                    for ($t = 1; $t <= 3; $t++) {
                        Task::factory()->create([
                            'project_id' => $project->id,
                            'story_id' => $story->id,
                            'titre' => "Task {$e}.{$s}.{$t}",
                            'ordre' => $t,
                        ]);
                    }
                }
            }

            // Standalone tasks
            for ($t = 1; $t <= 3; $t++) {
                Task::factory()->standalone()->create([
                    'project_id' => $project->id,
                    'titre' => "Standalone task {$t} for {$code}",
                ]);
            }

            // Dependencies between stories
            if (count($allStories) >= 4) {
                $depService->addDependency($allStories[2], $allStories[0]);
                $depService->addDependency($allStories[3], $allStories[1]);
            }
        }
    }
}
