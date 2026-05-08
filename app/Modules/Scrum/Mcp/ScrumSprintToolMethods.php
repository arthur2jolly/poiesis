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
use App\Modules\Scrum\Models\SprintItem;
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

    /**
     * commit_sprint MCP tool — POIESIS-10 / POIESIS-49 / POIESIS-106.
     *
     * Stable error keys exposed to MCP consumers:
     *   - commit.sprint_not_planned : sprint is not in status 'planned'
     *   - commit.has_errors         : validate_sprint_plan returned blocking errors
     *   - commit.another_active     : another sprint is already active in the project
     *
     * Soft-fail response (force=false + warnings present, no transition done):
     *   { state: 'warnings_pending', warnings: [...], sprint_identifier: 'PROJ-S1' }
     *
     * Success response (POIESIS-106):
     *   { sprint: { ...format() }, warnings: [...], placed_count: int }
     *   - warnings may include validate_sprint_plan items (when force=true) AND
     *     post-commit placement warnings (commit.no_board_columns, commit.column_wip_exceeded).
     *   - placed_count = number of sprint items auto-placed in the first board column
     *     during this commit (excludes items already placed beforehand).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sprintCommit(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['identifier'] ?? ''), $user);
        $force = (bool) ($params['force'] ?? false);

        // Status precheck (advisory): the lock-and-recheck inside the
        // transaction is the actual race-safe gate, but this short-circuit
        // gives non-planned callers a precise transition error rather than
        // spurious validation feedback.
        if ($sprint->status !== 'planned') {
            throw ValidationException::withMessages([
                'commit.sprint_not_planned' => ["Cannot commit a sprint in status '{$sprint->status}'. Only sprints in status 'planned' can be committed."],
            ]);
        }

        $validation = $this->computeSprintValidation($sprint);

        if ($validation['errors'] !== []) {
            throw ValidationException::withMessages([
                'commit.has_errors' => $this->formatValidationItems($validation['errors']),
            ]);
        }

        // Soft-fail: warnings present and not acknowledged. No exception, no
        // transition — caller must reissue with force=true to confirm.
        if ($validation['warnings'] !== [] && ! $force) {
            return [
                'state' => 'warnings_pending',
                'warnings' => $validation['warnings'],
                'sprint_identifier' => $sprint->identifier,
            ];
        }

        return DB::transaction(function () use ($sprint, $validation) {
            /** @var Sprint $locked */
            $locked = Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'planned') {
                throw ValidationException::withMessages([
                    'commit.sprint_not_planned' => ["Cannot commit a sprint in status '{$locked->status}'. Only sprints in status 'planned' can be committed."],
                ]);
            }

            Project::whereKey($locked->project_id)->lockForUpdate()->firstOrFail();
            $this->assertNoActiveSprintInProject($locked->project_id, $locked->id, 'commit.another_active');

            $locked->status = 'active';
            $locked->save();
            $locked->loadCount('items');

            // POIESIS-106: place every sprint item not yet placed in the first
            // board column. Strictly additive — items placed beforehand keep
            // their column. Same transaction as the status transition so a
            // placement failure rolls the whole commit back.
            $autoPlace = $this->autoPlaceSprintItemsOnBoard($locked);

            return [
                'sprint' => $locked->format(),
                'warnings' => array_merge($validation['warnings'], $autoPlace['warnings']),
                'placed_count' => $autoPlace['placed_count'],
            ];
        });
    }

    /**
     * Auto-place sprint items into the first board column on commit.
     *
     * Behaviour (POIESIS-106):
     *   - Picks the lowest-position ScrumColumn of the sprint's project. If none
     *     exist, returns a `commit.no_board_columns` warning and zero placements.
     *   - Iterates SprintItems of the sprint that have no existing
     *     ScrumItemPlacement row. Items already placed (regardless of column)
     *     are left untouched.
     *   - Appends each unplaced item to the end of the first column. Hard WIP
     *     limit is honoured: items that would exceed `limit_hard` are skipped
     *     and surfaced via a `commit.column_wip_exceeded` warning. The commit
     *     itself never fails because of WIP — the agent receives the list of
     *     skipped items and decides what to do.
     *
     * Caller MUST run this inside the same DB::transaction as the status
     * transition so a placement failure rolls back the commit.
     *
     * @return array{placed_count: int, warnings: array<int, array<string, mixed>>}
     */
    private function autoPlaceSprintItemsOnBoard(Sprint $sprint): array
    {
        /** @var ScrumColumn|null $firstColumn */
        $firstColumn = ScrumColumn::where('project_id', $sprint->project_id)
            ->orderBy('position')
            ->lockForUpdate()
            ->first();

        if ($firstColumn === null) {
            return [
                'placed_count' => 0,
                'warnings' => [[
                    'code' => 'commit.no_board_columns',
                    'message' => 'Project has no Scrum board columns configured. Sprint items were not placed automatically.',
                    'severity' => 'warning',
                ]],
            ];
        }

        // Lock the target column's placement set to serialise concurrent commits.
        ScrumItemPlacement::where('column_id', $firstColumn->id)->lockForUpdate()->get();

        $placedSprintItemIds = ScrumItemPlacement::query()
            ->whereIn('sprint_item_id', SprintItem::where('sprint_id', $sprint->id)->select('id'))
            ->pluck('sprint_item_id')
            ->all();

        $unplacedItems = SprintItem::where('sprint_id', $sprint->id)
            ->whereNotIn('id', $placedSprintItemIds)
            ->orderBy('position')
            ->with('artifact.artifactable')
            ->get();

        $count = ScrumItemPlacement::where('column_id', $firstColumn->id)->count();
        $placedCount = 0;
        $overflowIdentifiers = [];

        foreach ($unplacedItems as $item) {
            if ($firstColumn->limit_hard !== null && $count >= $firstColumn->limit_hard) {
                $overflowIdentifiers[] = $this->describeSprintItemForWarning($item);

                continue;
            }

            ScrumItemPlacement::create([
                'sprint_item_id' => $item->id,
                'column_id' => $firstColumn->id,
                'position' => $count,
            ]);
            $count++;
            $placedCount++;
        }

        $warnings = [];
        if ($overflowIdentifiers !== []) {
            $warnings[] = [
                'code' => 'commit.column_wip_exceeded',
                'message' => "Column '{$firstColumn->name}' reached its hard WIP limit ({$firstColumn->limit_hard}). Items not placed automatically: ".implode(', ', $overflowIdentifiers).'.',
                'severity' => 'warning',
                'column_name' => $firstColumn->name,
                'unplaced_items' => $overflowIdentifiers,
            ];
        }

        return ['placed_count' => $placedCount, 'warnings' => $warnings];
    }

    /**
     * Best-effort identifier for an unplaced sprint item, used in WIP warnings.
     */
    private function describeSprintItemForWarning(SprintItem $item): string
    {
        $artifactable = $item->artifact?->artifactable;
        if ($artifactable instanceof Story || $artifactable instanceof Task) {
            return (string) $artifactable->identifier;
        }

        return (string) $item->id;
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
