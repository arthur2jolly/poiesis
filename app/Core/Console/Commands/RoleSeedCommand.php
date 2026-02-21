<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Core\Support\Role;
use Illuminate\Console\Command;

class RoleSeedCommand extends Command
{
    protected $signature = 'role:seed';

    protected $description = 'Create sample users for each role and add them to a sample project';

    public function handle(): int
    {
        // Check if sample data already exists
        if (User::where('name', 'Administrator User')->exists()) {
            $this->warn('Sample users already exist. Skipping...');
            return self::SUCCESS;
        }

        // Create sample users for each role
        $users = [
            ['name' => 'Administrator User', 'role' => Role::ADMINISTRATOR],
            ['name' => 'Manager User', 'role' => Role::MANAGER],
            ['name' => 'Developer User', 'role' => Role::DEVELOPER],
            ['name' => 'Viewer User', 'role' => Role::VIEWER],
        ];

        foreach ($users as $userData) {
            $user = User::create([
                'name' => $userData['name'],
                'password' => 'SamplePassword123',
                'role' => $userData['role'],
            ]);

            $this->info("Created user: {$user->name} ({$user->getRoleName()})");
        }

        // Create a sample project
        $project = Project::firstOrCreate(
            ['code' => 'SAMPLE'],
            ['titre' => 'Sample Project', 'description' => 'A sample project for testing roles']
        );

        // Add all users to the project
        foreach ($users as $userData) {
            $user = User::where('name', $userData['name'])->first();

            $exists = ProjectMember::where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->exists();

            if (!$exists) {
                ProjectMember::create([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'role' => 'member', // Project-level role, not user role
                ]);

                $this->info("Added {$user->name} to project {$project->code}");
            }
        }

        $this->newLine();
        $this->info('Sample data created successfully!');
        $this->info("Default credentials: password='SamplePassword123'");
        $this->info("Sample project: {$project->code}");

        return self::SUCCESS;
    }
}
