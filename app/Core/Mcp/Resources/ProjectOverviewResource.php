<?php

declare(strict_types=1);

namespace App\Core\Mcp\Resources;

use App\Core\Mcp\Contracts\McpResourceInterface;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProjectOverviewResource implements McpResourceInterface
{
    /**
     * Returns the URI template for this resource.
     *
     * @return string URI template using `{code}` as the project code placeholder.
     */
    public function uri(): string
    {
        return 'project://{code}/overview';
    }

    /**
     * Returns the human-readable name of this resource.
     */
    public function name(): string
    {
        return 'Project Overview';
    }

    /**
     * Returns a short description of what this resource exposes.
     */
    public function description(): string
    {
        return 'Project summary with statistics (epics, stories, tasks counts and active modules)';
    }

    /**
     * Reads and returns the project overview payload.
     *
     * @param  array<string, mixed>  $params  URI parameters; must contain `code`.
     * @param  User  $user  Authenticated user (reserved for future access control).
     * @return array<string, mixed> Overview payload with counts and active modules.
     *
     * @throws ModelNotFoundException When no project matches the code.
     */
    public function read(array $params, User $user): array
    {
        $project = Project::where('code', $params['code'])->firstOrFail();

        $epicCount = $project->epics()->count();
        $storyCount = Story::whereHas('epic', fn ($q) => $q->where('project_id', $project->id))->count();
        $taskCount = $project->tasks()->count();

        return [
            'project_code' => $project->code,
            'titre' => $project->titre,
            'description' => $project->description,
            'epics_count' => $epicCount,
            'stories_count' => $storyCount,
            'tasks_count' => $taskCount,
            'active_modules' => $project->modules ?? [],
        ];
    }
}
