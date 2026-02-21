<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Console\Command;

class ProjectRemoveMemberCommand extends Command
{
    protected $signature = 'project:remove-member {code} {user}';

    protected $description = 'Remove a user from a project';

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

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        if ($member === null) {
            $this->error("\"{$user->name}\" is not a member of project \"{$project->code}\".");

            return self::FAILURE;
        }

        if (ProjectMember::isLastOwner($project->id, $user->id)) {
            $this->error("Cannot remove \"{$user->name}\": they are the last owner of \"{$project->code}\".");

            return self::FAILURE;
        }

        if (! $this->confirm("Remove \"{$user->name}\" from \"{$project->code}\"?", false)) {
            return self::SUCCESS;
        }

        $member->delete();

        $this->info("Removed \"{$user->name}\" from \"{$project->code}\".");

        return self::SUCCESS;
    }
}
