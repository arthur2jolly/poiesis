<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Core\Support\Role;
use App\Modules\Scrum\Models\Sprint;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

trait ScrumSharedToolMethods
{
    // ===== Helpers =====

    private function assertCanManage(User $user): void
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'sprint' => ['You do not have permission to manage sprints.'],
            ]);
        }
    }

    private function assertNoActiveSprintInProject(string $projectId, string $excludeSprintId, string $errorKey = 'sprint'): void
    {
        /** @var Sprint|null $existing */
        $existing = Sprint::where('project_id', $projectId)
            ->where('status', 'active')
            ->where('id', '!=', $excludeSprintId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $code = (string) Project::whereKey($projectId)->value('code');
            throw ValidationException::withMessages([
                $errorKey => ["Project '{$code}' already has an active sprint ({$existing->identifier}). Close or cancel it before starting a new one."],
            ]);
        }
    }

    private function parseDate(mixed $value, string $field): Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages([$field => ['Invalid date format. Expected YYYY-MM-DD.']]);
        }
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['Invalid date format. Expected YYYY-MM-DD.']]);
        }

        if ($date === null) {
            throw ValidationException::withMessages([$field => ['Invalid date format. Expected YYYY-MM-DD.']]);
        }

        return $date;
    }

    private function assertDateRange(Carbon $start, Carbon $end): void
    {
        if (! $start->lt($end)) {
            throw ValidationException::withMessages([
                'end_date' => ['end_date must be strictly greater than start_date.'],
            ]);
        }
    }

    private function normalizeCapacity(mixed $value, bool $exists): ?int
    {
        if (! $exists) {
            return null;
        }
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw ValidationException::withMessages([
                'capacity' => ['Capacity must be a non-negative integer.'],
            ]);
        }
        $int = (int) $value;
        if ($int < 0) {
            throw ValidationException::withMessages([
                'capacity' => ['Capacity must be a non-negative integer.'],
            ]);
        }

        return $int;
    }

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        if (! ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $this->assertScrumModuleActive($project);

        return $project;
    }

    /**
     * Resolve a sprint by identifier (PROJ-S{N}) and verify membership.
     *
     * QO-5: cross-project lookups return "Sprint not found." rather than "Access denied."
     * to avoid leaking the existence of sprints in projects the user doesn't belong to.
     */
    private function findSprint(string $identifier, User $user): Sprint
    {
        if (! preg_match('/^([A-Z0-9]+)-S(\d+)$/', $identifier, $m)) {
            throw ValidationException::withMessages([
                'identifier' => ['Invalid sprint identifier format.'],
            ]);
        }

        $project = Project::where('code', $m[1])->first();
        if ($project === null) {
            throw ValidationException::withMessages(['identifier' => ['Sprint not found.']]);
        }

        $isMember = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists();
        if (! $isMember) {
            throw ValidationException::withMessages(['identifier' => ['Sprint not found.']]);
        }

        $this->assertScrumModuleActive($project, 'identifier');

        $sprint = Sprint::where('project_id', $project->id)
            ->where('sprint_number', (int) $m[2])
            ->first();
        if ($sprint === null) {
            throw ValidationException::withMessages(['identifier' => ['Sprint not found.']]);
        }

        return $sprint;
    }

    private function assertScrumModuleActive(Project $project, string $field = 'project'): void
    {
        if (in_array('scrum', $project->modules ?? [], true)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => ["Module 'scrum' is not active for project '{$project->code}'."],
        ]);
    }
}
