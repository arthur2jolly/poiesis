<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Core\Support\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class EpicTools implements McpToolInterface
{
    /**
     * Returns the list of tool definitions provided by this class.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return [
            $this->getListEpicsDescription(),
            $this->getGetEpicDescription(),
            $this->getCreateEpicDescription(),
            $this->getUpdateEpicDescription(),
            $this->getDeleteEpicDescription(),
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
     * @throws ValidationException On invalid input or access denial.
     * @throws ModelNotFoundException When the project does not exist.
     */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'list_epics' => $this->listEpics($params, $user),
            'get_epic' => $this->getEpic($params, $user),
            'create_epic' => $this->createEpic($params, $user),
            'update_epic' => $this->updateEpic($params, $user),
            'delete_epic' => $this->deleteEpic($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    private function getListEpicsDescription(): array
    {
        return [
            'name' => 'list_epics',
            'description' => 'List epics of a project',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string', 'description' => 'Project code'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    private function getGetEpicDescription(): array
    {
        return [
            'name' => 'get_epic',
            'description' => 'Get epic details by its identifier',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string', 'description' => 'Artifact identifier (e.g. PROJ-1)'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function getCreateEpicDescription(): array
    {
        return [
            'name' => 'create_epic',
            'description' => 'Create an epic in a project',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'titre' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['project_code', 'titre'],
            ],
        ];
    }

    private function getUpdateEpicDescription(): array
    {
        return [
            'name' => 'update_epic',
            'description' => 'Update an epic',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'titre' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function getDeleteEpicDescription(): array
    {
        return [
            'name' => 'delete_epic',
            'description' => 'Delete an epic and all its stories',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function listEpics(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $perPage = min((int) ($params['per_page'] ?? 25), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $epics = $project->epics()->withCount('stories')->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $epics->map(fn (Epic $e) => $e->format())->all(),
            'meta' => [
                'current_page' => $epics->currentPage(),
                'per_page' => $epics->perPage(),
                'total' => $epics->total(),
                'last_page' => $epics->lastPage(),
            ],
        ];
    }

    private function getEpic(array $params, User $user): array
    {
        $epic = $this->resolveEpic($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $epic->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $epic->loadCount('stories');

        return $epic->format();
    }

    private function createEpic(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'epic' => ['You do not have permission to create epics.'],
            ]);
        }

        $project = $this->findProjectWithAccess($params['project_code'], $user);

        $epic = Epic::create([
            'project_id' => $project->id,
            'titre' => $params['titre'],
            'description' => $params['description'] ?? null,
        ]);

        $epic->load('artifact');
        $epic->loadCount('stories');

        return $epic->format();
    }

    private function updateEpic(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'epic' => ['You do not have permission to update epics.'],
            ]);
        }

        $epic = $this->resolveEpic($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $epic->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $data = array_filter([
            'titre' => $params['titre'] ?? null,
            'description' => array_key_exists('description', $params) ? $params['description'] : null,
        ], fn ($v) => $v !== null);

        if (! empty($data)) {
            $epic->update($data);
        }

        $epic->loadCount('stories');

        return $epic->format();
    }

    private function deleteEpic(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'epic' => ['You do not have permission to delete epics.'],
            ]);
        }

        $epic = $this->resolveEpic($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $epic->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $epic->delete();

        return ['message' => 'Epic deleted.'];
    }

    private function resolveEpic(string $identifier): Epic
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Epic) {
            throw ValidationException::withMessages([
                'identifier' => ["'{$identifier}' is not an epic."],
            ]);
        }

        return $model;
    }

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        if (! ProjectMember::where('project_id', $project->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        return $project;
    }
}
