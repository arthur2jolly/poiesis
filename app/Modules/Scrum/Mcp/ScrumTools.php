<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Core\Support\Role;
use App\Modules\Scrum\Models\Sprint;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScrumTools implements McpToolInterface
{
    /** @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function tools(): array
    {
        return [
            $this->getCreateSprintToolDescription(),
            $this->getListSprintsToolDescription(),
            $this->getGetSprintToolDescription(),
            $this->getUpdateSprintToolDescription(),
            $this->getDeleteSprintToolDescription(),
            $this->getStartSprintToolDescription(),
            $this->getCloseSprintToolDescription(),
            $this->getCancelSprintToolDescription(),
        ];
    }

    /** @param array<string, mixed> $params */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'create_sprint' => $this->sprintCreate($params, $user),
            'list_sprints' => $this->sprintList($params, $user),
            'get_sprint' => $this->sprintGet($params, $user),
            'update_sprint' => $this->sprintUpdate($params, $user),
            'delete_sprint' => $this->sprintDelete($params, $user),
            'start_sprint' => $this->sprintStart($params, $user),
            'close_sprint' => $this->sprintClose($params, $user),
            'cancel_sprint' => $this->sprintCancel($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    // ===== Tool implementations =====

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintCreate(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess((string) ($params['project_code'] ?? ''), $user);

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Sprint name is required.']]);
        }

        $start = $this->parseDate($params['start_date'] ?? null, 'start_date');
        $end = $this->parseDate($params['end_date'] ?? null, 'end_date');
        $this->assertDateRange($start, $end);

        $capacity = $this->normalizeCapacity($params['capacity'] ?? null, exists: array_key_exists('capacity', $params));
        $goal = isset($params['goal']) ? (trim((string) $params['goal']) ?: null) : null;

        $sprint = Sprint::create([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->id,
            'name' => $name,
            'goal' => $goal,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'capacity' => $capacity,
        ]);

        return $sprint->format();
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintUpdate(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['identifier'] ?? ''), $user);

        // QO-1: reject any attempt to change status via update_sprint.
        if (array_key_exists('status', $params)) {
            throw ValidationException::withMessages([
                'status' => ['Status cannot be changed via update_sprint. Use the dedicated sprint lifecycle tools.'],
            ]);
        }

        $data = [];

        if (array_key_exists('name', $params)) {
            $name = trim((string) $params['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => ['Sprint name is required.']]);
            }
            $data['name'] = $name;
        }

        if (array_key_exists('goal', $params)) {
            $goal = $params['goal'];
            if ($goal === null) {
                $data['goal'] = null;
            } else {
                $trimmed = trim((string) $goal);
                $data['goal'] = $trimmed === '' ? null : $trimmed;
            }
        }

        if (array_key_exists('capacity', $params)) {
            $data['capacity'] = $this->normalizeCapacity($params['capacity'], exists: true);
        }

        $finalStart = array_key_exists('start_date', $params)
            ? $this->parseDate($params['start_date'], 'start_date')
            : Carbon::parse($sprint->start_date->toDateString());
        $finalEnd = array_key_exists('end_date', $params)
            ? $this->parseDate($params['end_date'], 'end_date')
            : Carbon::parse($sprint->end_date->toDateString());

        if (array_key_exists('start_date', $params) || array_key_exists('end_date', $params)) {
            $this->assertDateRange($finalStart, $finalEnd);
        }
        if (array_key_exists('start_date', $params)) {
            $data['start_date'] = $finalStart->toDateString();
        }
        if (array_key_exists('end_date', $params)) {
            $data['end_date'] = $finalEnd->toDateString();
        }

        if ($data !== []) {
            $sprint->update($data);
        }

        $sprint->loadCount('items');

        return $sprint->format();
    }

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

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintDelete(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['identifier'] ?? ''), $user);

        if (in_array($sprint->status, ['active', 'completed'], true)) {
            throw ValidationException::withMessages([
                'sprint' => ['Cannot delete a sprint that is active or completed. Cancel it first or wait for completion.'],
            ]);
        }

        $sprint->delete();

        return ['message' => 'Sprint deleted.'];
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintStart(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['identifier'] ?? ''), $user);

        return DB::transaction(function () use ($sprint) {
            /** @var Sprint $locked */
            $locked = Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'planned') {
                throw ValidationException::withMessages([
                    'sprint' => ["Cannot start a sprint in status '{$locked->status}'. Only sprints in status 'planned' can be started."],
                ]);
            }

            $this->assertNoActiveSprintInProject($locked->project_id, $locked->id);

            $locked->status = 'active';
            $locked->save();
            $locked->loadCount('items');

            return $locked->format();
        });
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintClose(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['identifier'] ?? ''), $user);

        return DB::transaction(function () use ($sprint) {
            /** @var Sprint $locked */
            $locked = Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages([
                    'sprint' => ["Cannot close a sprint in status '{$locked->status}'. Only sprints in status 'active' can be closed."],
                ]);
            }

            $locked->status = 'completed';
            $locked->closed_at = Carbon::now();
            $locked->save();
            $locked->loadCount('items');

            return $locked->format();
        });
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintCancel(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['identifier'] ?? ''), $user);

        return DB::transaction(function () use ($sprint) {
            /** @var Sprint $locked */
            $locked = Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['planned', 'active'], true)) {
                throw ValidationException::withMessages([
                    'sprint' => ["Cannot cancel a sprint in status '{$locked->status}'. Only sprints in status 'planned' or 'active' can be cancelled."],
                ]);
            }

            $locked->status = 'cancelled';
            // closed_at intentionally NOT touched (RM-05.4 / QO-2)
            $locked->save();
            $locked->loadCount('items');

            return $locked->format();
        });
    }

    // ===== Helpers =====

    private function assertCanManage(User $user): void
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'sprint' => ['You do not have permission to manage sprints.'],
            ]);
        }
    }

    private function assertNoActiveSprintInProject(string $projectId, string $excludeSprintId): void
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
                'sprint' => ["Project '{$code}' already has an active sprint ({$existing->identifier}). Close or cancel it before starting a new one."],
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
    private function getCreateSprintToolDescription(): array
    {
        return [
            'name' => 'create_sprint',
            'description' => 'Create a sprint in a project (status: planned)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'name' => ['type' => 'string', 'description' => 'Sprint name'],
                    'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'goal' => ['type' => 'string', 'description' => 'Sprint goal (optional)'],
                    'capacity' => ['type' => 'integer', 'description' => 'Capacity in story points (optional, >= 0)'],
                ],
                'required' => ['project_code', 'name', 'start_date', 'end_date'],
            ],
        ];
    }

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

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getUpdateSprintToolDescription(): array
    {
        return [
            'name' => 'update_sprint',
            'description' => 'Update a sprint (descriptive fields only — status is changed via dedicated tools)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'goal' => ['type' => ['string', 'null']],
                    'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'capacity' => ['type' => ['integer', 'null']],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getDeleteSprintToolDescription(): array
    {
        return [
            'name' => 'delete_sprint',
            'description' => 'Delete a sprint (refused if status is active or completed)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getStartSprintToolDescription(): array
    {
        return [
            'name' => 'start_sprint',
            'description' => 'Start a sprint (planned -> active). Fails if another sprint is already active in the project.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getCloseSprintToolDescription(): array
    {
        return [
            'name' => 'close_sprint',
            'description' => 'Close an active sprint (active -> completed). Sets closed_at to current UTC timestamp.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getCancelSprintToolDescription(): array
    {
        return [
            'name' => 'cancel_sprint',
            'description' => 'Cancel a sprint (planned|active -> cancelled). Items remain attached. closed_at is not set.',
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
