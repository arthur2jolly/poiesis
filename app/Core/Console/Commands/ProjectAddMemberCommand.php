<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Console\Command;

class ProjectAddMemberCommand extends Command
{
    protected $signature = 'project:add-member {code} {user} {--role=member}';

    protected $description = 'Add a user to a project';

    public function handle(): int
    {
        $project = Project::where('code', $this->argument('code'))->first();

        if ($project === null) {
            $this->error("Project not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        $user = User::where('name', $this->argument('user'))->first();

        if ($user === null) {
            $this->error("User not found: {$this->argument('user')}");

            return self::FAILURE;
        }

        $role = $this->option('role');
        $validRoles = config('core.project_roles');

        if (! in_array($role, $validRoles, true)) {
            $this->error("Invalid role \"{$role}\". Valid roles: ".implode(', ', $validRoles));

            return self::FAILURE;
        }

        $exists = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            $this->error("\"{$user->name}\" is already a member of project \"{$project->code}\".");

            return self::FAILURE;
        }

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        $this->info("Added \"{$user->name}\" to \"{$project->code}\" as {$role}.");

        return self::SUCCESS;
    }
}
