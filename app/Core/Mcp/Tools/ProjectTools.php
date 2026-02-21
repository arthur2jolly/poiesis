<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Core\Support\Role;
use Illuminate\Validation\ValidationException;

class ProjectTools implements McpToolInterface
{
    /**
     * Returns the list of tool definitions provided by this class.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return [
            $this->getListProjectsDescription(),
            $this->getGetProjectDescription(),
            $this->getCreateProjectDescription(),
            $this->getUpdateProjectDescription(),
            $this->getDeleteProjectDescription(),
        ];
    }

    /**
     * Dispatches execution to the appropriate private method.
     *
     * @param  string  $toolName  Name of the tool to execute.
     * @param  array<string, mixed>  $params  Input arguments provided by the caller.
     * @param  User  $user  Authenticated user performing the action.
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the tool name is not handled by this class.
     * @throws \Illuminate\Validation\ValidationException On invalid input or access denial.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When the project does not exist.
     */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'list_projects' => $this->listProjects($params, $user),
            'get_project' => $this->getProject($params, $user),
            'create_project' => $this->createProject($params, $user),
            'update_project' => $this->updateProject($params, $user),
            'delete_project' => $this->deleteProject($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    private function getListProjectsDescription(): array
    {
        return [
            'name' => 'list_projects',
            'description' => 'Lists projects accessible by the agent',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer', 'description' => 'Page number'],
                    'per_page' => ['type' => 'integer', 'description' => 'Items per page (max 100)'],
                ],
            ],
        ];
    }

    private function getGetProjectDescription(): array
    {
        return [
            'name' => 'get_project',
            'description' => 'Get project details by its code',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string', 'description' => 'Project code'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    private function getCreateProjectDescription(): array
    {
        return [
            'name' => 'create_project',
            'description' => 'Create a new project',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'Unique project code (2-25 chars, [A-Za-z0-9-])'],
                    'titre' => ['type' => 'string', 'description' => 'Project title'],
                    'description' => ['type' => 'string', 'description' => 'Project description'],
                ],
                'required' => ['code', 'titre'],
            ],
        ];
    }

    private function getUpdateProjectDescription(): array
    {
        return [
            'name' => 'update_project',
            'description' => 'Update a project',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string', 'description' => 'Project code'],
                    'titre' => ['type' => 'string', 'description' => 'New title'],
                    'description' => ['type' => 'string', 'description' => 'New description'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    private function getDeleteProjectDescription(): array
    {
        return [
            'name' => 'delete_project',
            'description' => 'Delete a project (owner only)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string', 'description' => 'Project code'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    private function listProjects(array $params, User $user): array
    {
        $perPage = min((int) ($params['per_page'] ?? 25), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $projects = Project::accessibleBy($user)->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $projects->map(fn (Project $p) => $p->format())->all(),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
                'last_page' => $projects->lastPage(),
            ],
        ];
    }

    private function getProject(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);

        return $project->format();
    }

    private function createProject(array $params, User $user): array
    {
        if (! Role::canCrudProjects($user->role)) {
            throw ValidationException::withMessages([
                'project' => ['You do not have permission to create projects.'],
            ]);
        }

        if (! preg_match('/^[A-Za-z0-9\-]{2,25}$/', $params['code'] ?? '')) {
            throw ValidationException::withMessages([
                'code' => ['The code must be 2-25 characters, containing only letters, digits, and hyphens.'],
            ]);
        }

        if (Project::where('code', $params['code'])->exists()) {
            throw ValidationException::withMessages([
                'code' => ['A project with this code already exists.'],
            ]);
        }

        $project = Project::create([
            'code' => $params['code'],
            'titre' => $params['titre'],
            'description' => $params['description'] ?? null,
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => config('core.default_project_member_role', 'owner'),
        ]);

        return $project->format();
    }

    private function updateProject(array $params, User $user): array
    {
        if (! Role::canCrudProjects($user->role)) {
            throw ValidationException::withMessages([
                'project' => ['You do not have permission to update projects.'],
            ]);
        }

        $project = $this->findProjectWithAccess($params['project_code'], $user);

        $data = array_filter([
            'titre' => $params['titre'] ?? null,
            'description' => array_key_exists('description', $params) ? $params['description'] : null,
        ], fn ($v) => $v !== null);

        if (! empty($data)) {
            $project->update($data);
        }

        return $project->format();
    }

    private function deleteProject(array $params, User $user): array
    {
        if (! Role::canCrudProjects($user->role)) {
            throw ValidationException::withMessages([
                'project' => ['You do not have permission to delete projects.'],
            ]);
        }

        $project = $this->findProjectWithAccess($params['project_code'], $user);

        $isOwner = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();

        if (! $isOwner) {
            throw ValidationException::withMessages([
                'project' => ['Only a project owner can delete it.'],
            ]);
        }

        $project->delete();

        return ['message' => 'Project deleted.'];
    }

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        $isMember = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                'project' => ['Access denied.'],
            ]);
        }

        return $project;
    }
}
