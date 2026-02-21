<?php

namespace App\Core\Console\Commands;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use Illuminate\Console\Command;

class ProjectMembersCommand extends Command
{
    protected $signature = 'project:members {code}';

    protected $description = 'List members of a project';

    public function handle(): int
    {
        $project = Project::where('code', $this->argument('code'))->first();

        if ($project === null) {
            $this->error("Project not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        $members = $project->members()->with('user')->get();

        $this->table(
            ['User ID', 'Name', 'Role', 'Member Since'],
            $members->map(fn (ProjectMember $m) => [
                $m->user_id,
                $m->user->name,
                $m->role,
                $m->created_at->toDateTimeString(),
            ])
        );

        return self::SUCCESS;
    }
}
