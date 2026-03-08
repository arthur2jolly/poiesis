<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Console\Command;

class ProjectUpdateMemberCommand extends Command
{
    protected $signature = 'project:update-member {code} {user} {--policy=} {--position=}';

    protected $description = 'Update a project member\'s role or policy';

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

        $membership = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            $this->error("\"{$user->name}\" is not a member of project \"{$project->code}\".");

            return self::FAILURE;
        }

        $policy = $this->option('policy');
        $position = $this->option('position');

        if ($policy === null && $position === null) {
            $this->error('Provide at least --policy or --position.');

            return self::FAILURE;
        }

        if ($policy !== null) {
            $validPolicies = array_change_key_case(config('core.user_roles_int'), CASE_LOWER);
            $policyInt = $validPolicies[strtolower($policy)] ?? null;

            if ($policyInt === null) {
                $this->error("Invalid policy \"{$policy}\". Valid policies: ".implode(', ', array_keys($validPolicies)));

                return self::FAILURE;
            }

            $user->role = $policyInt;
            $user->save();
        }

        if ($position !== null) {
            $validPositions = config('core.project_positions');

            if (! in_array($position, $validPositions, true)) {
                $this->error("Invalid position \"{$position}\". Valid positions: ".implode(', ', $validPositions));

                return self::FAILURE;
            }

            $membership->position = $position;
            $membership->save();
        }

        $this->info("Updated \"{$user->name}\" in \"{$project->code}\".");

        return self::SUCCESS;
    }
}
