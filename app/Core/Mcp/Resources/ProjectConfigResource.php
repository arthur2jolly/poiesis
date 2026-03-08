<?php

declare(strict_types=1);

namespace App\Core\Mcp\Resources;

use App\Core\Mcp\Contracts\McpResourceInterface;
use App\Core\Models\Project;
use App\Core\Models\User;

class ProjectConfigResource implements McpResourceInterface
{
    /**
     * Returns the URI template for this resource.
     *
     * @return string URI template using `{code}` as the project code placeholder.
     */
    public function uri(): string
    {
        return 'project://{code}/config';
    }

    /**
     * Returns the human-readable name of this resource.
     */
    public function name(): string
    {
        return 'Project Configuration';
    }

    /**
     * Returns a short description of what this resource exposes.
     */
    public function description(): string
    {
        return 'Allowed business values (types, priorities, natures, statuts, roles) and project-specific active modules';
    }

    /**
     * Reads and returns the project configuration payload.
     *
     * @param  array<string, mixed>  $params  URI parameters; must contain `code`.
     * @param  User  $user  Authenticated user (reserved for future access control).
     * @return array<string, mixed> Configuration payload with all enumerated values and active modules.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When no project matches the code.
     */
    public function read(array $params, User $user): array
    {
        $project = Project::where('code', $params['code'])->firstOrFail();

        return [
            'project_code' => $project->code,
            'item_types' => config('core.item_types'),
            'priorities' => config('core.priorities'),
            'default_priority' => config('core.default_priority'),
            'statuts' => config('core.statuts'),
            'default_statut' => config('core.default_statut'),
            'work_natures' => config('core.work_natures'),
            'project_positions' => config('core.project_positions'),
            'user_policies' => array_map('strtolower', config('core.user_roles')),
            'oauth_scopes' => config('core.oauth_scopes'),
            'active_modules' => $project->modules ?? [],
        ];
    }
}
