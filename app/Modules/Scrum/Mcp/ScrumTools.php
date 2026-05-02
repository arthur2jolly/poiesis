<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Modules\Scrum\Models\Sprint;
use Illuminate\Validation\ValidationException;

class ScrumTools implements McpToolInterface
{
    /** @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function tools(): array
    {
        return [
            $this->getListSprintsToolDescription(),
            $this->getGetSprintToolDescription(),
        ];
    }

    /** @param array<string, mixed> $params */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'list_sprints' => $this->sprintList($params, $user),
            'get_sprint' => $this->sprintGet($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    // ===== Tool implementations =====

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintList(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess((string) ($params['project_code'] ?? ''), $user);

        if (array_key_exists('status', $params) && $params['status'] !== null) {
            if (! in_array($params['status'], config('core.sprint_statuses'), true)) {
                throw ValidationException::withMessages(['status' => ['Invalid sprint status.']]);
            }
        }

        $perPage = min(max((int) ($params['per_page'] ?? 25), 1), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $query = Sprint::where('project_id', $project->id)->withCount('items');
        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        $query->orderBy('start_date', 'desc')->orderBy('sprint_number', 'desc');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->map(fn (Sprint $s) => $s->format())->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintGet(array $params, User $user): array
    {
        $sprint = $this->findSprint((string) ($params['identifier'] ?? ''), $user);
        $sprint->loadCount('items');

        return $sprint->format();
    }

    // ===== Helpers =====

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        if (! ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

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

        $sprint = Sprint::where('project_id', $project->id)
            ->where('sprint_number', (int) $m[2])
            ->first();
        if ($sprint === null) {
            throw ValidationException::withMessages(['identifier' => ['Sprint not found.']]);
        }

        return $sprint;
    }

    // ===== Tool descriptions =====

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getListSprintsToolDescription(): array
    {
        return [
            'name' => 'list_sprints',
            'description' => 'List sprints of a project (paginated)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => config('core.sprint_statuses')],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getGetSprintToolDescription(): array
    {
        return [
            'name' => 'get_sprint',
            'description' => 'Get sprint details by identifier (e.g. PROJ-S1)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }
}
