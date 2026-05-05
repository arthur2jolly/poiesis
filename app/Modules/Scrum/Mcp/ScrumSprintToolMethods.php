<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Modules\Scrum\Models\ScrumColumn;
use App\Modules\Scrum\Models\ScrumItemPlacement;
use App\Modules\Scrum\Models\Sprint;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ScrumSprintToolMethods
{
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
    private function sprintCommit(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['identifier'] ?? ''), $user);
        $force = (bool) ($params['force'] ?? false);

        // Status check up-front so callers committing a non-planned sprint
        // still get the precise transition error rather than spurious
        // validation feedback. A second check inside the transaction below
        // remains the actual race-safe gate.
        if ($sprint->status !== 'planned') {
            throw ValidationException::withMessages([
                'sprint' => ["Cannot commit a sprint in status '{$sprint->status}'. Only sprints in status 'planned' can be committed."],
            ]);
        }

        $validation = $this->sprintValidatePlan(
            ['sprint_identifier' => $sprint->identifier],
            $user
        );

        if ($validation['errors'] !== []) {
            throw ValidationException::withMessages([
                'sprint' => [
                    "Cannot commit sprint plan. Blocking errors:\n  - "
                    .implode("\n  - ", $this->formatValidationItems($validation['errors'])),
                ],
            ]);
        }

        if ($validation['warnings'] !== [] && ! $force) {
            throw ValidationException::withMessages([
                'sprint' => [
                    "Sprint plan has warnings. Pass force=true to acknowledge and commit anyway:\n  - "
                    .implode("\n  - ", $this->formatValidationItems($validation['warnings'])),
                ],
            ]);
        }

        return DB::transaction(function () use ($sprint) {
            /** @var Sprint $locked */
            $locked = Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'planned') {
                throw ValidationException::withMessages([
                    'sprint' => ["Cannot commit a sprint in status '{$locked->status}'. Only sprints in status 'planned' can be committed."],
                ]);
            }

            Project::whereKey($locked->project_id)->lockForUpdate()->firstOrFail();
            $this->assertNoActiveSprintInProject($locked->project_id, $locked->id);

            $locked->status = 'active';
            $locked->save();
            $locked->loadCount('items');

            return $locked->format();
        });
    }

    /**
     * Flatten validate_sprint_plan items into human-readable lines.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function formatValidationItems(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $code = (string) ($item['code'] ?? 'unknown');
            $message = (string) ($item['message'] ?? '');
            $lines[] = "[{$code}] {$message}";
        }

        return $lines;
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
            $this->closeSprintArtifacts($locked);
            $this->moveSprintPlacementsToDone($locked);
            $locked->loadCount('items');

            return $locked->format();
        });
    }

    private function closeSprintArtifacts(Sprint $sprint): void
    {
        $items = $sprint->items()->with(['artifact.artifactable'])->get();

        foreach ($items as $item) {
            $artifactable = $item->artifact?->artifactable;

            if ($artifactable instanceof Story) {
                foreach ($artifactable->tasks()->get() as $task) {
                    if ($task instanceof Task) {
                        $this->closeWorkItem($task);
                    }
                }
                $this->closeWorkItem($artifactable);
            } elseif ($artifactable instanceof Task) {
                $this->closeWorkItem($artifactable);
            }
        }
    }

    private function closeWorkItem(Story|Task $item): void
    {
        if ($item->statut === 'closed') {
            return;
        }

        if ($item->statut === 'draft') {
            $item->transitionStatus('open');
        }

        $item->transitionStatus('closed');
    }

    private function moveSprintPlacementsToDone(Sprint $sprint): void
    {
        /** @var ScrumColumn|null $doneColumn */
        $doneColumn = ScrumColumn::where('project_id', $sprint->project_id)
            ->where('name', 'Done')
            ->first();

        if (! $doneColumn instanceof ScrumColumn) {
            return;
        }

        $placements = ScrumItemPlacement::whereHas(
            'sprintItem',
            fn ($query) => $query->where('sprint_id', $sprint->id)
        )->get();

        $sourceColumnIds = $placements
            ->pluck('column_id')
            ->push($doneColumn->id)
            ->unique()
            ->values();

        $nextPosition = ScrumItemPlacement::where('column_id', $doneColumn->id)
            ->whereDoesntHave('sprintItem', fn ($query) => $query->where('sprint_id', $sprint->id))
            ->max('position');
        $position = $nextPosition === null ? 0 : ((int) $nextPosition) + 1;

        foreach ($placements as $placement) {
            $placement->column_id = $doneColumn->id;
            $placement->position = $position++;
            $placement->save();
        }

        foreach ($sourceColumnIds as $columnId) {
            $this->recompactColumnPositions((string) $columnId);
        }
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
}
