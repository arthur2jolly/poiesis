<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Services\DependencyService;
use App\Core\Support\Role;
use App\Modules\Scrum\Models\ScrumColumn;
use App\Modules\Scrum\Models\ScrumItemPlacement;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
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
            $this->getAddToSprintToolDescription(),
            $this->getRemoveFromSprintToolDescription(),
            $this->getListSprintItemsToolDescription(),
            $this->getListBacklogToolDescription(),
            $this->getReorderBacklogToolDescription(),
            $this->getEstimateStoryToolDescription(),
            $this->getMarkReadyToolDescription(),
            $this->getMarkUnreadyToolDescription(),
            $this->getStartPlanningToolDescription(),
            $this->getAddToPlanningToolDescription(),
            $this->getRemoveFromPlanningToolDescription(),
            $this->getValidateSprintPlanToolDescription(),
            $this->getBoardBuildToolDescription(),
            $this->getBoardGetToolDescription(),
            $this->getColumnCreateToolDescription(),
            $this->getColumnUpdateToolDescription(),
            $this->getColumnDeleteToolDescription(),
            $this->getColumnReorderToolDescription(),
            $this->getItemPlaceToolDescription(),
            $this->getItemMoveToolDescription(),
            $this->getItemUnplaceToolDescription(),
            $this->getColumnItemsToolDescription(),
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
            'add_to_sprint' => $this->sprintItemAdd($params, $user),
            'remove_from_sprint' => $this->sprintItemRemove($params, $user),
            'list_sprint_items' => $this->sprintItemList($params, $user),
            'list_backlog' => $this->backlogList($params, $user),
            'reorder_backlog' => $this->backlogReorder($params, $user),
            'estimate_story' => $this->storyEstimate($params, $user),
            'mark_ready' => $this->storyMarkReady($params, $user),
            'mark_unready' => $this->storyMarkUnready($params, $user),
            'start_planning' => $this->planningStart($params, $user),
            'add_to_planning' => $this->planningAdd($params, $user),
            'remove_from_planning' => $this->planningRemove($params, $user),
            'validate_sprint_plan' => $this->sprintValidatePlan($params, $user),
            'scrum_board_build' => $this->boardBuild($params, $user),
            'scrum_board_get' => $this->boardGet($params, $user),
            'scrum_column_create' => $this->columnCreate($params, $user),
            'scrum_column_update' => $this->columnUpdate($params, $user),
            'scrum_column_delete' => $this->columnDelete($params, $user),
            'scrum_column_reorder' => $this->columnReorder($params, $user),
            'scrum_item_place' => $this->itemPlace($params, $user),
            'scrum_item_move' => $this->itemMove($params, $user),
            'scrum_item_unplace' => $this->itemUnplace($params, $user),
            'scrum_column_items' => $this->columnItems($params, $user),
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

            Project::whereKey($locked->project_id)->lockForUpdate()->firstOrFail();
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

    // ===== Sprint items =====

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintItemAdd(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['sprint_identifier'] ?? ''), $user);

        if (in_array($sprint->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'sprint' => ["Cannot add items to a sprint in status '{$sprint->status}'. Only sprints in status 'planned' or 'active' accept new items."],
            ]);
        }

        $artifactRow = $this->resolveSprintItemArtifact(
            (string) ($params['item_identifier'] ?? ''),
            $sprint
        );

        // Definition of Ready: stories must be marked ready before being
        // added to a sprint. Standalone tasks bypass this check (DoR is a
        // story concept). Aligns add_to_sprint with add_to_planning so an
        // agent cannot bypass the gate by going through the items API.
        $artifactRow->loadMissing('artifactable');
        $model = $artifactRow->artifactable;
        if ($model instanceof Story && $model->ready !== true) {
            $missing = $this->dorMissingFields($model);
            throw ValidationException::withMessages([
                'item' => ['Story is not ready. Missing: '.implode(', ', $missing).'.'],
            ]);
        }

        $position = null;
        if (array_key_exists('position', $params) && $params['position'] !== null) {
            if (! is_int($params['position']) || $params['position'] < 0) {
                throw ValidationException::withMessages([
                    'position' => ['Position must be a non-negative integer.'],
                ]);
            }
            $position = $params['position'];
        }

        return DB::transaction(function () use ($sprint, $artifactRow, $position) {
            Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            $existing = SprintItem::where('artifact_id', $artifactRow->id)->first();
            if ($existing !== null) {
                if ($existing->sprint_id === $sprint->id) {
                    throw ValidationException::withMessages([
                        'item' => ['Item is already in this sprint.'],
                    ]);
                }
                $other = Sprint::find($existing->sprint_id);
                $otherIdentifier = $other instanceof Sprint ? $other->identifier : 'unknown';
                throw ValidationException::withMessages([
                    'item' => ["Item is already attached to sprint {$otherIdentifier}. Remove it from there first."],
                ]);
            }

            $finalPosition = $position ?? (((int) ($sprint->items()->max('position') ?? -1)) + 1);

            try {
                $item = SprintItem::create([
                    'sprint_id' => $sprint->id,
                    'artifact_id' => $artifactRow->id,
                    'position' => $finalPosition,
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23000') {
                    throw ValidationException::withMessages([
                        'item' => ['Item is already attached to another sprint.'],
                    ]);
                }
                throw $e;
            }

            $item->load(['sprint', 'artifact.artifactable']);

            return $item->format();
        });
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintItemRemove(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['sprint_identifier'] ?? ''), $user);

        if (in_array($sprint->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'sprint' => ["Cannot remove items from a sprint in status '{$sprint->status}'. Only sprints in status 'planned' or 'active' can be modified."],
            ]);
        }

        $artifactRow = $this->resolveSprintItemArtifact(
            (string) ($params['item_identifier'] ?? ''),
            $sprint
        );

        $item = SprintItem::where('sprint_id', $sprint->id)
            ->where('artifact_id', $artifactRow->id)
            ->first();

        if ($item === null) {
            throw ValidationException::withMessages(['item' => ['Item is not in this sprint.']]);
        }

        $item->delete();

        return ['message' => 'Item removed from sprint.'];
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sprintItemList(array $params, User $user): array
    {
        $sprint = $this->findSprint((string) ($params['sprint_identifier'] ?? ''), $user);

        $items = $sprint->items()->with(['sprint', 'artifact.artifactable'])->get();

        return [
            'data' => $items->map(fn (SprintItem $i) => $i->format())->all(),
            'meta' => ['total' => $items->count()],
        ];
    }

    /**
     * Resolve a Story or standalone Task identifier and verify project ownership.
     *
     * Returns the artifactable model (Story | Task).
     */
    private function resolveSprintItemArtifact(string $identifier, Sprint $sprint): Artifact
    {
        $model = Artifact::resolveIdentifier($identifier);

        if ($model === null) {
            throw ValidationException::withMessages(['item' => ['Item not found.']]);
        }

        if ($model instanceof Epic) {
            throw ValidationException::withMessages([
                'item' => ['Only stories and standalone tasks can be added to a sprint. Epics are not sprintable.'],
            ]);
        }

        if ($model instanceof Story) {
            $itemProjectId = $model->epic->project_id;
        } elseif ($model instanceof Task) {
            if (! $model->isStandalone()) {
                throw ValidationException::withMessages([
                    'item' => ['Only standalone tasks (not linked to a story) can be added to a sprint individually. Add the parent story instead.'],
                ]);
            }
            $itemProjectId = $model->project_id;
        } else {
            throw ValidationException::withMessages(['item' => ['Item not found.']]);
        }

        if ($itemProjectId !== $sprint->project_id) {
            throw ValidationException::withMessages([
                'item' => ['Item not found.'],
            ]);
        }

        /** @var Artifact|null $artifactRow */
        $artifactRow = Artifact::where('artifactable_id', $model->getKey())
            ->where('artifactable_type', $model->getMorphClass())
            ->where('tenant_id', $sprint->tenant_id)
            ->where('project_id', $sprint->project_id)
            ->first();

        if (! $artifactRow instanceof Artifact) {
            throw ValidationException::withMessages(['item' => ['Item not found.']]);
        }

        return $artifactRow;
    }

    // ===== Backlog =====

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function backlogList(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess((string) ($params['project_code'] ?? ''), $user);

        if (array_key_exists('status', $params) && $params['status'] !== null
            && ! in_array($params['status'], $this->backlogStatuses(), true)) {
            throw ValidationException::withMessages(['status' => ['Invalid story status.']]);
        }
        if (array_key_exists('priority', $params) && $params['priority'] !== null
            && ! in_array($params['priority'], config('core.priorities'), true)) {
            throw ValidationException::withMessages(['priority' => ['Invalid story priority.']]);
        }

        $epic = null;
        if (! empty($params['epic_identifier'])) {
            $epicId = (string) $params['epic_identifier'];
            if (! preg_match('/^[A-Z0-9]+-\d+$/', $epicId)) {
                throw ValidationException::withMessages(['epic_identifier' => ['Invalid epic identifier format.']]);
            }
            $resolved = Artifact::resolveIdentifier($epicId);
            if (! $resolved instanceof Epic || $resolved->project_id !== $project->id) {
                throw ValidationException::withMessages(['epic_identifier' => ['Epic not found in this project.']]);
            }
            $epic = $resolved;
        }

        $perPage = min(max((int) ($params['per_page'] ?? 25), 1), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $query = Story::whereHas('epic', fn ($q) => $q->where('project_id', $project->id))
            ->where('statut', '!=', 'closed')
            ->with('epic');

        if (! empty($params['status'])) {
            $query->where('statut', $params['status']);
        }
        if (! empty($params['priority'])) {
            $query->where('priorite', $params['priority']);
        }
        if (! empty($params['tags']) && is_array($params['tags'])) {
            foreach ($params['tags'] as $tag) {
                $query->whereJsonContains('tags', $tag);
            }
        }
        if ($epic !== null) {
            $query->where('epic_id', $epic->id);
        }

        if (array_key_exists('in_sprint', $params) && $params['in_sprint'] !== null) {
            $sprintActiveStoryIds = DB::table('scrum_sprint_items')
                ->join('scrum_sprints', 'scrum_sprints.id', '=', 'scrum_sprint_items.sprint_id')
                ->join('artifacts', 'artifacts.id', '=', 'scrum_sprint_items.artifact_id')
                ->whereIn('scrum_sprints.status', ['planned', 'active'])
                ->where('scrum_sprints.project_id', $project->id)
                ->where('artifacts.artifactable_type', Story::class)
                ->pluck('artifacts.artifactable_id');

            if ((bool) $params['in_sprint'] === true) {
                $query->whereIn('id', $sprintActiveStoryIds);
            } else {
                $query->whereNotIn('id', $sprintActiveStoryIds);
            }
        }

        $query->orderByRaw('(rank IS NULL), rank ASC, created_at ASC');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->map(fn (Story $s) => $this->formatBacklogStory($s))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /** @return array<int, string> */
    private function backlogStatuses(): array
    {
        return array_values(array_filter(
            config('core.statuts'),
            fn (string $status): bool => $status !== 'closed'
        ));
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function backlogReorder(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess((string) ($params['project_code'] ?? ''), $user);

        $ordered = $params['ordered_identifiers'] ?? [];
        if (! is_array($ordered) || $ordered === []) {
            throw ValidationException::withMessages([
                'ordered_identifiers' => ['ordered_identifiers cannot be empty.'],
            ]);
        }

        // 1. Format + project membership of each identifier
        foreach ($ordered as $id) {
            if (! is_string($id) || ! preg_match('/^([A-Z0-9]+)-(\d+)$/', $id, $m) || $m[1] !== $project->code) {
                throw ValidationException::withMessages([
                    'ordered_identifiers' => ["Identifier '{$id}' does not belong to project '{$project->code}'."],
                ]);
            }
        }

        // 2. No duplicates
        $duplicates = array_diff_assoc($ordered, array_unique($ordered));
        if ($duplicates !== []) {
            $first = reset($duplicates);
            throw ValidationException::withMessages([
                'ordered_identifiers' => ["Duplicate identifier in ordered_identifiers: '{$first}'."],
            ]);
        }

        // 3. Resolve each identifier into a Story belonging to this project
        $resolvedIds = [];
        foreach ($ordered as $id) {
            $story = $this->resolveStoryInProject((string) $id, $project->id);
            $resolvedIds[] = $story->id;
        }

        // 4. Coverage exacte des stories non-closed du projet
        $existingNonClosed = Story::whereHas(
            'epic',
            fn ($q) => $q->where('project_id', $project->id)
        )->where('statut', '!=', 'closed')->pluck('id')->all();

        $missing = array_diff($existingNonClosed, $resolvedIds);
        $unexpected = array_diff($resolvedIds, $existingNonClosed);

        if ($missing !== [] || $unexpected !== []) {
            $missingIdentifiers = $this->identifiersForStoryIds(array_values($missing));
            $unexpectedIdentifiers = $this->identifiersForStoryIds(array_values($unexpected));
            throw ValidationException::withMessages([
                'ordered_identifiers' => [
                    'Reorder coverage mismatch. Missing: ['
                    .implode(', ', $missingIdentifiers).']. Unexpected: ['
                    .implode(', ', $unexpectedIdentifiers).'].',
                ],
            ]);
        }

        // 5. Atomic write
        DB::transaction(function () use ($resolvedIds) {
            Story::whereIn('id', $resolvedIds)->lockForUpdate()->get();
            foreach ($resolvedIds as $index => $storyId) {
                Story::where('id', $storyId)->update(['rank' => $index]);
            }
        });

        // 6. Return refreshed backlog
        $backlog = $this->backlogList([
            'project_code' => $project->code,
            'per_page' => count($resolvedIds),
            'page' => 1,
        ], $user);

        return [
            'message' => 'Backlog reordered.',
            'count' => count($resolvedIds),
            'data' => $backlog['data'],
        ];
    }

    /** @return array<string, mixed> */
    private function formatBacklogStory(Story $story): array
    {
        return [
            'identifier' => $story->identifier,
            'titre' => $story->titre,
            'description' => $story->description,
            'statut' => $story->statut,
            'priorite' => $story->priorite,
            'tags' => $story->tags,
            'story_points' => $story->story_points,
            'ready' => $story->ready,
            'rank' => $story->rank,
            'epic_identifier' => $story->epic->identifier,
            'created_at' => $story->created_at->toIso8601String(),
            'updated_at' => $story->updated_at->toIso8601String(),
        ];
    }

    private function resolveStoryInProject(string $identifier, string $projectId): Story
    {
        $model = Artifact::resolveIdentifier($identifier);
        if (! $model instanceof Story || $model->epic->project_id !== $projectId) {
            throw ValidationException::withMessages([
                'ordered_identifiers' => ["Story '{$identifier}' not found in this project."],
            ]);
        }

        return $model;
    }

    /** @param array<int, mixed> $storyIds
     * @return array<int, string>
     */
    private function identifiersForStoryIds(array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        return Artifact::where('artifactable_type', Story::class)
            ->whereIn('artifactable_id', $storyIds)
            ->pluck('identifier')
            ->all();
    }

    // ===== POIESIS-7: Estimation & Definition of Ready =====

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function storyEstimate(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $story = $this->resolveStoryFromIdentifier(
            (string) ($params['story_identifier'] ?? ''),
            $user
        );

        $points = $this->normalizeStoryPoints(
            $params['story_points'] ?? null,
            exists: array_key_exists('story_points', $params)
        );

        DB::transaction(function () use ($story, $points) {
            /** @var Story $locked */
            $locked = Story::whereKey($story->id)->lockForUpdate()->firstOrFail();
            $locked->story_points = $points;
            $locked->save();
        });

        $story->refresh()->load('epic');

        return $this->formatBacklogStory($story);
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function storyMarkReady(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $story = $this->resolveStoryFromIdentifier(
            (string) ($params['story_identifier'] ?? ''),
            $user
        );

        $this->assertReadyDoR($story);

        DB::transaction(function () use ($story) {
            /** @var Story $locked */
            $locked = Story::whereKey($story->id)->lockForUpdate()->firstOrFail();
            $this->assertReadyDoR($locked);

            if ($locked->ready === true) {
                return;
            }

            $locked->ready = true;
            $locked->save();
        });

        $story->refresh()->load('epic');

        return $this->formatBacklogStory($story);
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function storyMarkUnready(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $story = $this->resolveStoryFromIdentifier(
            (string) ($params['story_identifier'] ?? ''),
            $user
        );

        if ($story->ready === false) {
            return $this->formatBacklogStory($story->load('epic'));
        }

        $story->ready = false;
        $story->save();

        $story->refresh()->load('epic');

        return $this->formatBacklogStory($story);
    }

    // ===== POIESIS-8: Planning session =====

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function planningStart(array $params, User $user): array
    {
        $sprint = $this->findSprint((string) ($params['sprint_identifier'] ?? ''), $user);

        if ($sprint->status !== 'planned') {
            throw ValidationException::withMessages([
                'sprint' => ["Cannot start planning on a sprint in status '{$sprint->status}'. Only sprints in status 'planned' support planning."],
            ]);
        }

        $engagedItems = $sprint->items()->with(['sprint', 'artifact.artifactable'])->get();

        $readyStories = Story::whereHas('epic', fn ($q) => $q->where('project_id', $sprint->project_id))
            ->where('statut', 'open')
            ->where('ready', true)
            ->whereNotIn('id', $this->storyIdsInActivePlanningSprints($sprint->project_id))
            ->with('epic')
            ->orderByRaw('(rank IS NULL), rank ASC, created_at ASC')
            ->limit(100)
            ->get();

        $summary = $this->computePlanningSummary($sprint, $engagedItems);

        return [
            'sprint' => $sprint->loadCount('items')->format(),
            'capacity' => $summary['capacity'],
            'engaged_points' => $summary['engaged_points'],
            'ratio_engaged' => $summary['ratio_engaged'],
            'engaged_items' => $engagedItems->map(fn (SprintItem $i) => $i->format())->all(),
            'ready_backlog' => $readyStories->map(fn (Story $s) => $this->formatBacklogStory($s))->all(),
        ];
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function planningAdd(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['sprint_identifier'] ?? ''), $user);

        if ($sprint->status !== 'planned') {
            throw ValidationException::withMessages([
                'sprint' => ["Cannot add to planning on a sprint in status '{$sprint->status}'. Only sprints in status 'planned' support planning."],
            ]);
        }

        $identifiers = $this->normalizeStoryIdentifiers($params['story_identifiers'] ?? null);

        /** @var array<string, Story> $stories */
        $stories = $this->validateStoriesForPlanning($sprint, $identifiers, 'add');

        $createdItems = DB::transaction(function () use ($sprint, $stories) {
            Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();
            $maxPosition = (int) ($sprint->items()->max('position') ?? -1);

            $storyIds = array_map(fn (Story $s) => $s->id, array_values($stories));
            $artifactRows = Artifact::where('artifactable_type', Story::class)
                ->whereIn('artifactable_id', $storyIds)
                ->get()->keyBy('artifactable_id');
            $artifactIds = $artifactRows->pluck('id')->all();

            SprintItem::whereIn('artifact_id', $artifactIds)
                ->whereIn('sprint_id', Sprint::where('project_id', $sprint->project_id)
                    ->whereNotIn('status', ['planned', 'active'])
                    ->select('id'))
                ->delete();

            $created = [];
            foreach (array_values($stories) as $i => $story) {
                /** @var Artifact $artifactRow */
                $artifactRow = $artifactRows[$story->id];
                $created[] = SprintItem::create([
                    'sprint_id' => $sprint->id,
                    'artifact_id' => $artifactRow->id,
                    'position' => $maxPosition + 1 + $i,
                ])->load(['sprint', 'artifact.artifactable']);
            }

            return $created;
        });

        $sprint->refresh();
        $allItems = $sprint->items()->with(['sprint', 'artifact.artifactable'])->get();
        $summary = $this->computePlanningSummary($sprint, $allItems);

        return [
            'message' => 'Stories added to planning.',
            'added_count' => count($createdItems),
            'added_items' => array_map(fn (SprintItem $i) => $i->format(), $createdItems),
            'capacity' => $summary['capacity'],
            'engaged_points' => $summary['engaged_points'],
            'ratio_engaged' => $summary['ratio_engaged'],
        ];
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function planningRemove(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $sprint = $this->findSprint((string) ($params['sprint_identifier'] ?? ''), $user);

        if ($sprint->status !== 'planned') {
            throw ValidationException::withMessages([
                'sprint' => ["Cannot remove from planning on a sprint in status '{$sprint->status}'. Only sprints in status 'planned' support planning."],
            ]);
        }

        $identifiers = $this->normalizeStoryIdentifiers($params['story_identifiers'] ?? null);

        /** @var array<string, Story> $stories */
        $stories = $this->validateStoriesForPlanning($sprint, $identifiers, 'remove');

        $removedIdentifiers = array_keys($stories);

        DB::transaction(function () use ($sprint, $stories) {
            Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();
            $storyIds = array_map(fn (Story $s) => $s->id, array_values($stories));
            $artifactIds = Artifact::where('artifactable_type', Story::class)
                ->whereIn('artifactable_id', $storyIds)
                ->pluck('id')->all();
            SprintItem::where('sprint_id', $sprint->id)
                ->whereIn('artifact_id', $artifactIds)
                ->delete();
        });

        $sprint->refresh();
        $allItems = $sprint->items()->with(['sprint', 'artifact.artifactable'])->get();
        $summary = $this->computePlanningSummary($sprint, $allItems);

        return [
            'message' => 'Stories removed from planning.',
            'removed_count' => count($removedIdentifiers),
            'removed_identifiers' => $removedIdentifiers,
            'capacity' => $summary['capacity'],
            'engaged_points' => $summary['engaged_points'],
            'ratio_engaged' => $summary['ratio_engaged'],
        ];
    }

    // ===== POIESIS-9: Sprint plan validation =====

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sprintValidatePlan(array $params, User $user): array
    {
        $sprint = $this->findSprint((string) ($params['sprint_identifier'] ?? ''), $user);

        /** @var Collection<int, SprintItem> $items */
        $items = $sprint->items()->with(['artifact.artifactable'])->orderBy('position')->get();

        $errors = [];
        $warnings = [];

        // RM-04: empty sprint short-circuits per-item checks (RM-05 / RM-06).
        if ($items->isEmpty()) {
            $errors[] = $this->collectEmptySprintError();
        } else {
            foreach ($this->collectMissingEstimationErrors($items) as $err) {
                $errors[] = $err;
            }
            foreach ($this->collectBlockingDependencyErrors($items) as $err) {
                $errors[] = $err;
            }
        }

        $summary = $this->computePlanningSummary($sprint, $items);

        // RM-07: over capacity
        $overCapacity = $this->collectCapacityWarning($summary);
        if ($overCapacity !== null) {
            $warnings[] = $overCapacity;
        }

        // RM-08: missing goal (always evaluated)
        $missingGoal = $this->collectGoalWarning($sprint);
        if ($missingGoal !== null) {
            $warnings[] = $missingGoal;
        }

        return [
            'ok' => $errors === [],
            'sprint_identifier' => $sprint->identifier,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'items_count' => $items->count(),
                'engaged_points' => $summary['engaged_points'],
                'capacity' => $summary['capacity'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function collectEmptySprintError(): array
    {
        return [
            'code' => 'empty_sprint',
            'message' => 'Sprint has no items. A sprint must contain at least one story or task.',
            'severity' => 'error',
        ];
    }

    /**
     * @param  Collection<int, SprintItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function collectMissingEstimationErrors(Collection $items): array
    {
        $errors = [];
        foreach ($items as $item) {
            $artifactable = $item->artifact?->artifactable;
            if (! $artifactable instanceof Story) {
                continue;
            }
            if ($artifactable->story_points !== null) {
                continue;
            }
            $errors[] = [
                'code' => 'missing_estimation',
                'message' => "Story {$artifactable->identifier} has no estimation (story_points is null).",
                'severity' => 'error',
                'item_identifier' => $artifactable->identifier,
            ];
        }

        return $errors;
    }

    /**
     * @param  Collection<int, SprintItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function collectBlockingDependencyErrors(Collection $items): array
    {
        /** @var DependencyService $dependencyService */
        $dependencyService = app(DependencyService::class);
        $errors = [];
        $sprintPositions = $this->collectSprintArtifactPositions($items);

        foreach ($items as $item) {
            /** @var Story|Task|null $artifactable */
            $artifactable = $item->artifact?->artifactable;
            if (! $artifactable instanceof Story && ! $artifactable instanceof Task) {
                continue;
            }
            $blockers = $dependencyService->getDependencies($artifactable)['blocked_by'];
            foreach ($blockers as $blocker) {
                /** @var Story|Task $blocker */
                $status = $blocker->statut;
                if ($status === 'closed') {
                    continue;
                }
                if ($this->isDependencySequencedInsideSprint($artifactable, $blocker, $sprintPositions)) {
                    continue;
                }
                $itemIdentifier = (string) $artifactable->identifier;
                $blockerIdentifier = (string) $blocker->identifier;
                $errors[] = [
                    'code' => 'blocking_dependency',
                    'message' => "Item {$itemIdentifier} is blocked by {$blockerIdentifier} (status: {$status}).",
                    'severity' => 'error',
                    'item_identifier' => $itemIdentifier,
                    'blocking_identifier' => $blockerIdentifier,
                    'blocking_status' => $status,
                ];
            }
        }

        return $errors;
    }

    /**
     * @param  Collection<int, SprintItem>  $items
     * @return array<string, int>
     */
    private function collectSprintArtifactPositions(Collection $items): array
    {
        $positions = [];

        foreach ($items as $item) {
            $artifactable = $item->artifact?->artifactable;
            if (! $artifactable instanceof Model) {
                continue;
            }

            $positions[$this->artifactableSprintKey($artifactable)] = $item->position;
        }

        return $positions;
    }

    /** @param array<string, int> $sprintPositions */
    private function isDependencySequencedInsideSprint(Model $item, Model $blocker, array $sprintPositions): bool
    {
        $itemPosition = $sprintPositions[$this->artifactableSprintKey($item)] ?? null;
        $blockerPosition = $sprintPositions[$this->artifactableSprintKey($blocker)] ?? null;

        return $itemPosition !== null
            && $blockerPosition !== null
            && $blockerPosition < $itemPosition;
    }

    private function artifactableSprintKey(Model $model): string
    {
        return $model->getMorphClass().':'.(string) $model->getKey();
    }

    /**
     * @param  array{capacity: int|null, engaged_points: int, ratio_engaged: float|null}  $summary
     * @return array<string, mixed>|null
     */
    private function collectCapacityWarning(array $summary): ?array
    {
        $capacity = $summary['capacity'];
        $engaged = $summary['engaged_points'];

        if ($capacity === null || $capacity <= 0 || $engaged <= $capacity) {
            return null;
        }

        return [
            'code' => 'over_capacity',
            'message' => "Sprint engages {$engaged} story points but capacity is {$capacity}.",
            'severity' => 'warning',
            'engaged_points' => $engaged,
            'capacity' => $capacity,
        ];
    }

    /** @return array<string, mixed>|null */
    private function collectGoalWarning(Sprint $sprint): ?array
    {
        if ($sprint->goal !== null && trim((string) $sprint->goal) !== '') {
            return null;
        }

        return [
            'code' => 'missing_goal',
            'message' => 'Sprint has no goal defined. A sprint goal helps the team focus.',
            'severity' => 'warning',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStoryIdentifiers(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            throw ValidationException::withMessages([
                'story_identifiers' => ['story_identifiers cannot be empty.'],
            ]);
        }
        foreach ($raw as $id) {
            if (! is_string($id)) {
                throw ValidationException::withMessages([
                    'story_identifiers' => ['story_identifiers must be a list of strings.'],
                ]);
            }
        }
        /** @var array<int, string> $list */
        $list = array_values($raw);
        $duplicates = array_diff_assoc($list, array_unique($list));
        if ($duplicates !== []) {
            $first = (string) reset($duplicates);
            throw ValidationException::withMessages([
                'story_identifiers' => ["Duplicate identifier in story_identifiers: '{$first}'."],
            ]);
        }

        return $list;
    }

    /**
     * Validates a batch of identifiers for the planning context.
     * Mode 'add'   : DoR + closed + project + not in any sprint.
     * Mode 'remove': project + currently attached to THIS sprint.
     *
     * @param  array<int, string>  $identifiers
     * @return array<string, Story> Keyed by identifier (preserved insertion order)
     */
    private function validateStoriesForPlanning(Sprint $sprint, array $identifiers, string $mode): array
    {
        $violations = [];
        $resolved = [];

        foreach ($identifiers as $id) {
            if (! preg_match('/^([A-Z0-9]+)-(\d+)$/', $id)) {
                $violations[] = "{$id}: invalid identifier format.";

                continue;
            }
            $model = Artifact::resolveIdentifier($id);

            if (! $model instanceof Story) {
                $violations[] = $model === null
                    ? "{$id}: not found in this project."
                    : "{$id}: not a story.";

                continue;
            }
            if ($model->epic->project_id !== $sprint->project_id) {
                $violations[] = "{$id}: not found in this project.";

                continue;
            }

            if ($mode === 'add') {
                if ($model->statut === 'closed') {
                    $violations[] = "{$id}: cannot plan a closed story.";

                    continue;
                }
                if ($model->statut !== 'open') {
                    $violations[] = "{$id}: must be open to plan.";

                    continue;
                }
                if ($model->ready !== true) {
                    $missing = $this->dorMissingFields($model);
                    $violations[] = "{$id}: not ready (missing: ".implode(', ', $missing).').';

                    continue;
                }
                $existing = $this->findSprintItemForStory($model);
                if ($existing !== null) {
                    if ($existing->sprint_id === $sprint->id) {
                        $violations[] = "{$id}: already in this sprint.";
                    } else {
                        $other = Sprint::find($existing->sprint_id);
                        $otherIdentifier = $other instanceof Sprint ? $other->identifier : 'unknown';
                        $violations[] = "{$id}: already in sprint {$otherIdentifier}.";
                    }

                    continue;
                }
            } else { // remove
                $existing = $this->findSprintItemForStory($model);
                if ($existing === null || $existing->sprint_id !== $sprint->id) {
                    $violations[] = "{$id}: not in this sprint.";

                    continue;
                }
            }

            $resolved[$id] = $model;
        }

        if ($violations !== []) {
            $header = $mode === 'add'
                ? 'Cannot add stories to planning. Violations:'
                : 'Cannot remove stories from planning. Violations:';
            throw ValidationException::withMessages([
                'story_identifiers' => [
                    $header."\n  - ".implode("\n  - ", $violations),
                ],
            ]);
        }

        return $resolved;
    }

    /** @return array<int, string> */
    private function dorMissingFields(Story $story): array
    {
        $missing = [];
        if ($story->story_points === null) {
            $missing[] = 'story_points';
        }
        if ($story->description === null || trim((string) $story->description) === '') {
            $missing[] = 'description';
        }

        return $missing === [] ? ['ready=false'] : $missing;
    }

    private function findSprintItemForStory(Story $story): ?SprintItem
    {
        $artifactId = Artifact::where('artifactable_type', Story::class)
            ->where('artifactable_id', $story->id)
            ->value('id');
        if ($artifactId === null) {
            return null;
        }

        /** @var SprintItem|null $item */
        $item = SprintItem::where('artifact_id', $artifactId)
            ->whereIn('sprint_id', Sprint::where('project_id', $story->epic->project_id)
                ->whereIn('status', ['planned', 'active'])
                ->select('id'))
            ->first();

        return $item;
    }

    /**
     * @param  Collection<int, SprintItem>  $items
     * @return array{capacity: int|null, engaged_points: int, ratio_engaged: float|null}
     */
    private function computePlanningSummary(Sprint $sprint, Collection $items): array
    {
        $engagedPoints = 0;
        foreach ($items as $item) {
            $artifactable = $item->artifact?->artifactable;
            if ($artifactable instanceof Story) {
                $engagedPoints += (int) ($artifactable->story_points ?? 0);
            }
        }

        $capacity = $sprint->capacity;
        $ratio = ($capacity === null || $capacity === 0)
            ? null
            : round($engagedPoints / $capacity, 2);

        return [
            'capacity' => $capacity,
            'engaged_points' => $engagedPoints,
            'ratio_engaged' => $ratio,
        ];
    }

    /** @return array<int, mixed> Story IDs already in planned/active sprints of the project */
    private function storyIdsInActivePlanningSprints(string $projectId): array
    {
        return DB::table('scrum_sprint_items')
            ->join('scrum_sprints', 'scrum_sprints.id', '=', 'scrum_sprint_items.sprint_id')
            ->join('artifacts', 'artifacts.id', '=', 'scrum_sprint_items.artifact_id')
            ->whereIn('scrum_sprints.status', ['planned', 'active'])
            ->where('scrum_sprints.project_id', $projectId)
            ->where('artifacts.artifactable_type', Story::class)
            ->pluck('artifacts.artifactable_id')
            ->all();
    }

    private function resolveStoryFromIdentifier(string $identifier, User $user): Story
    {
        if (! preg_match('/^([A-Z0-9]+)-(\d+)$/', $identifier, $m)) {
            throw ValidationException::withMessages([
                'story_identifier' => ['Invalid story identifier format.'],
            ]);
        }

        $project = Project::where('code', $m[1])->first();
        if ($project === null) {
            throw ValidationException::withMessages([
                'story_identifier' => ['Story not found in this project.'],
            ]);
        }

        $isMember = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists();
        if (! $isMember) {
            throw ValidationException::withMessages([
                'story_identifier' => ['Story not found in this project.'],
            ]);
        }

        $model = Artifact::resolveIdentifier($identifier);
        if (! $model instanceof Story || $model->epic->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'story_identifier' => ['Story not found in this project.'],
            ]);
        }

        return $model;
    }

    private function assertReadyDoR(Story $story): void
    {
        $missing = [];
        if ($story->story_points === null) {
            $missing[] = 'story_points';
        }
        if ($story->description === null || trim((string) $story->description) === '') {
            $missing[] = 'description';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'ready' => ['Story is not ready. Missing: '.implode(', ', $missing).'.'],
            ]);
        }
    }

    private function normalizeStoryPoints(mixed $value, bool $exists): int
    {
        if (! $exists || $value === null) {
            throw ValidationException::withMessages([
                'story_points' => ['story_points must be a non-negative integer.'],
            ]);
        }
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw ValidationException::withMessages([
                'story_points' => ['story_points must be a non-negative integer.'],
            ]);
        }
        $int = (int) $value;
        if ($int < 0) {
            throw ValidationException::withMessages([
                'story_points' => ['story_points must be a non-negative integer.'],
            ]);
        }

        return $int;
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

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getAddToSprintToolDescription(): array
    {
        return [
            'name' => 'add_to_sprint',
            'description' => 'Add a story or standalone task to a sprint backlog. Sprint must be in status planned or active. Stories must be marked ready (Definition of Ready) — use mark_ready first if needed. Standalone tasks bypass the DoR check.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sprint_identifier' => ['type' => 'string', 'description' => 'Sprint identifier (e.g. PROJ-S1)'],
                    'item_identifier' => ['type' => 'string', 'description' => 'Story or standalone task identifier (e.g. PROJ-12)'],
                    'position' => ['type' => 'integer', 'description' => 'Optional 0-indexed position (defaults to append)'],
                ],
                'required' => ['sprint_identifier', 'item_identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getRemoveFromSprintToolDescription(): array
    {
        return [
            'name' => 'remove_from_sprint',
            'description' => 'Remove a story or task from a sprint backlog (sprint must be in status planned or active)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sprint_identifier' => ['type' => 'string'],
                    'item_identifier' => ['type' => 'string'],
                ],
                'required' => ['sprint_identifier', 'item_identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getListSprintItemsToolDescription(): array
    {
        return [
            'name' => 'list_sprint_items',
            'description' => 'List items of a sprint with their position and artifact details',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sprint_identifier' => ['type' => 'string'],
                ],
                'required' => ['sprint_identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getListBacklogToolDescription(): array
    {
        return [
            'name' => 'list_backlog',
            'description' => 'List the project backlog (stories ordered by rank ASC NULLS LAST, then created_at ASC). Supports filters by status, priority, tags, epic, and sprint membership.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => $this->backlogStatuses(), 'description' => 'Filter by story status'],
                    'priority' => ['type' => 'string', 'enum' => config('core.priorities'), 'description' => 'Filter by story priority'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'AND filter on tags'],
                    'epic_identifier' => ['type' => 'string', 'description' => 'Restrict to a single epic (e.g. PROJ-1)'],
                    'in_sprint' => ['type' => 'boolean', 'description' => 'true = in a planned/active sprint, false = otherwise'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer', 'description' => 'Default 25, max 100'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getReorderBacklogToolDescription(): array
    {
        return [
            'name' => 'reorder_backlog',
            'description' => 'Reorder the project backlog. Must cover exactly all non-closed stories. Index 0 = highest priority.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'ordered_identifiers' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Ordered list of story identifiers (index 0 = highest priority). Must cover exactly all non-closed stories of the project.',
                    ],
                ],
                'required' => ['project_code', 'ordered_identifiers'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getEstimateStoryToolDescription(): array
    {
        return [
            'name' => 'estimate_story',
            'description' => 'Set or update the story_points estimation of a story (any non-negative integer; no Fibonacci constraint).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'story_identifier' => ['type' => 'string', 'description' => 'Story identifier (e.g. PROJ-12)'],
                    'story_points' => ['type' => 'integer', 'description' => 'Non-negative integer (>= 0)'],
                ],
                'required' => ['story_identifier', 'story_points'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getMarkReadyToolDescription(): array
    {
        return [
            'name' => 'mark_ready',
            'description' => 'Mark a story as ready (Definition of Ready). Requires story_points set and a non-empty description. Idempotent if already ready.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'story_identifier' => ['type' => 'string', 'description' => 'Story identifier (e.g. PROJ-12)'],
                ],
                'required' => ['story_identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getMarkUnreadyToolDescription(): array
    {
        return [
            'name' => 'mark_unready',
            'description' => 'Mark a story as not ready. Always allowed (no DoR check). Idempotent if already not ready.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'story_identifier' => ['type' => 'string', 'description' => 'Story identifier (e.g. PROJ-12)'],
                ],
                'required' => ['story_identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getStartPlanningToolDescription(): array
    {
        return [
            'name' => 'start_planning',
            'description' => 'Open a sprint planning session. Returns the sprint, capacity summary, currently engaged items, and the ready backlog (stories with ready=true, status=open, not in any planned/active sprint, ordered by rank). Sprint must be in status planned. Read-only — accessible to any project member.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sprint_identifier' => ['type' => 'string', 'description' => 'Sprint identifier (e.g. PROJ-S1). Sprint must be in status planned.'],
                ],
                'required' => ['sprint_identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getAddToPlanningToolDescription(): array
    {
        return [
            'name' => 'add_to_planning',
            'description' => 'Engage one or more stories in the sprint planning. All stories must be ready=true, status=open, in the same project, and not in any planned/active sprint. Atomic: a single violation refuses the whole batch. Sprint must be in status planned.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sprint_identifier' => ['type' => 'string'],
                    'story_identifiers' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Story identifiers (e.g. ["PROJ-12", "PROJ-15"]). Duplicates are rejected.',
                    ],
                ],
                'required' => ['sprint_identifier', 'story_identifiers'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getRemoveFromPlanningToolDescription(): array
    {
        return [
            'name' => 'remove_from_planning',
            'description' => 'Disengage one or more stories from the sprint planning. All stories must currently be attached to this sprint. Atomic: a single violation refuses the whole batch. Sprint must be in status planned.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sprint_identifier' => ['type' => 'string'],
                    'story_identifiers' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['sprint_identifier', 'story_identifiers'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getValidateSprintPlanToolDescription(): array
    {
        return [
            'name' => 'validate_sprint_plan',
            'description' => 'Diagnose a sprint before start. Read-only. Returns { ok, errors[], warnings[], summary } covering: empty_sprint (error), missing_estimation (error, per story), blocking_dependency (error, per unresolved dependency), over_capacity (warning), missing_goal (warning). Does not block start_sprint — purely informational. Works on any sprint status.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sprint_identifier' => [
                        'type' => 'string',
                        'description' => 'Sprint identifier (e.g. PROJ-S1).',
                    ],
                ],
                'required' => ['sprint_identifier'],
            ],
        ];
    }

    // ===== POIESIS-11: Scrum Board Foundation =====

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getBoardBuildToolDescription(): array
    {
        return [
            'name' => 'scrum_board_build',
            'description' => 'Build (or rebuild, if no items are placed) the Scrum board configuration for a project. Replaces any existing column setup.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string', 'description' => 'Project code (e.g. POIESIS).'],
                    'columns' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'description' => 'Ordered list of columns. Position is derived from array order (0..N-1).',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'limit_warning' => ['type' => 'integer', 'description' => 'WIP warning threshold (>= 1, optional).'],
                                'limit_hard' => ['type' => 'integer', 'description' => 'WIP hard limit (>= 1, optional, must be > limit_warning if both set).'],
                            ],
                            'required' => ['name'],
                        ],
                    ],
                ],
                'required' => ['project_code', 'columns'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getBoardGetToolDescription(): array
    {
        return [
            'name' => 'scrum_board_get',
            'description' => 'Read the Scrum board for a project: columns (ordered by position) with their placements grouped and ordered by position. Returns columns: [] when not yet configured.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function boardBuild(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess((string) ($params['project_code'] ?? ''), $user);

        $columns = $params['columns'] ?? null;
        if (! is_array($columns) || $columns === []) {
            throw ValidationException::withMessages(['columns' => ['At least one column is required.']]);
        }

        // Pre-validate every column before any DB write (atomicity).
        $normalized = [];
        foreach ($columns as $col) {
            if (! is_array($col)) {
                throw ValidationException::withMessages(['columns' => ['Column name is required.']]);
            }
            $name = trim((string) ($col['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages(['columns' => ['Column name is required.']]);
            }

            $limitWarning = array_key_exists('limit_warning', $col) ? $col['limit_warning'] : null;
            $limitHard = array_key_exists('limit_hard', $col) ? $col['limit_hard'] : null;

            $limitWarning = $limitWarning === null ? null : (int) $limitWarning;
            $limitHard = $limitHard === null ? null : (int) $limitHard;

            $this->validateColumnLimits($limitWarning, $limitHard);

            $normalized[] = [
                'name' => $name,
                'limit_warning' => $limitWarning,
                'limit_hard' => $limitHard,
            ];
        }

        return DB::transaction(function () use ($project, $normalized) {
            // Guard: refuse rebuild if placements exist for this project's columns.
            $hasPlacements = ScrumItemPlacement::whereIn(
                'column_id',
                ScrumColumn::where('project_id', $project->id)->pluck('id')
            )->exists();

            if ($hasPlacements) {
                throw ValidationException::withMessages([
                    'board' => ['Cannot rebuild board: items are currently placed. Clear placements first.'],
                ]);
            }

            // Delete existing columns (no placements — guard above ensures this).
            ScrumColumn::where('project_id', $project->id)->delete();

            $created = [];
            foreach ($normalized as $idx => $row) {
                $created[] = ScrumColumn::create([
                    'tenant_id' => $project->tenant_id,
                    'project_id' => $project->id,
                    'name' => $row['name'],
                    'position' => $idx,
                    'limit_warning' => $row['limit_warning'],
                    'limit_hard' => $row['limit_hard'],
                ]);
            }

            return [
                'message' => 'Board built.',
                'project_code' => $project->code,
                'columns' => array_map(fn (ScrumColumn $c) => $c->format(), $created),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function boardGet(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess((string) ($params['project_code'] ?? ''), $user);

        $columns = ScrumColumn::where('project_id', $project->id)
            ->with(['placements.sprintItem.sprint', 'placements.sprintItem.artifact.artifactable'])
            ->withCount('placements')
            ->orderBy('position')
            ->get();

        return [
            'project_code' => $project->code,
            'columns' => $columns->map(function (ScrumColumn $col): array {
                $visiblePlacements = $col->placements
                    ->filter(fn (ScrumItemPlacement $placement) => $this->isReadyStoryPlacement($placement))
                    ->values();
                $base = $col->format();
                $base['placement_count'] = $visiblePlacements->count();
                $base['at_warning'] = $col->limit_warning !== null && $visiblePlacements->count() >= $col->limit_warning;
                $base['at_hard_limit'] = $col->limit_hard !== null && $visiblePlacements->count() >= $col->limit_hard;
                $base['placements'] = $visiblePlacements
                    ->map(fn (ScrumItemPlacement $p) => $p->format())
                    ->all();

                return $base;
            })->all(),
        ];
    }

    private function validateColumnLimits(?int $warning, ?int $hard): void
    {
        if ($warning !== null && $warning < 1) {
            throw ValidationException::withMessages(['limit_warning' => ['Warning limit must be a positive integer.']]);
        }

        if ($hard !== null && $hard < 1) {
            throw ValidationException::withMessages(['limit_hard' => ['Hard limit must be a positive integer.']]);
        }

        if ($warning !== null && $hard !== null && $warning >= $hard) {
            throw ValidationException::withMessages(['limit_warning' => ['Warning limit must be less than hard limit.']]);
        }
    }

    // ===== POIESIS-12: Scrum Column CRUD =====

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getColumnCreateToolDescription(): array
    {
        return [
            'name' => 'scrum_column_create',
            'description' => 'Append a new column at the end of the Scrum board (position = max+1). Use scrum_column_reorder afterwards to insert at a specific position.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'name' => ['type' => 'string', 'description' => 'Column name (trim non-empty).'],
                    'limit_warning' => ['type' => ['integer', 'null'], 'description' => 'WIP warning threshold (>= 1, optional).'],
                    'limit_hard' => ['type' => ['integer', 'null'], 'description' => 'WIP hard limit (>= 1, optional, must be > limit_warning if both set).'],
                ],
                'required' => ['project_code', 'name'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getColumnUpdateToolDescription(): array
    {
        return [
            'name' => 'scrum_column_update',
            'description' => 'Update name and/or WIP limits of a column. At least one field is required. Position is managed exclusively by scrum_column_reorder.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'column_id' => ['type' => 'string', 'description' => 'Column UUID (from scrum_board_get).'],
                    'name' => ['type' => 'string'],
                    'limit_warning' => ['type' => ['integer', 'null'], 'description' => 'null = remove threshold.'],
                    'limit_hard' => ['type' => ['integer', 'null'], 'description' => 'null = remove hard limit.'],
                ],
                'required' => ['column_id'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getColumnDeleteToolDescription(): array
    {
        return [
            'name' => 'scrum_column_delete',
            'description' => 'Delete an empty column. Refused if the column contains placements (move them first). No automatic position compaction — call scrum_column_reorder to recompact.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'column_id' => ['type' => 'string'],
                ],
                'required' => ['column_id'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getColumnReorderToolDescription(): array
    {
        return [
            'name' => 'scrum_column_reorder',
            'description' => 'Reorder all columns of a project. column_ids must cover exactly all columns of the project (no missing, no extra). Index 0 = position 0.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'column_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Ordered list of column UUIDs. Must cover exactly all columns of the project (index 0 = position 0).',
                    ],
                ],
                'required' => ['project_code', 'column_ids'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function columnCreate(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess((string) ($params['project_code'] ?? ''), $user);

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Column name is required.']]);
        }

        $limitWarning = array_key_exists('limit_warning', $params) && $params['limit_warning'] !== null
            ? (int) $params['limit_warning'] : null;
        $limitHard = array_key_exists('limit_hard', $params) && $params['limit_hard'] !== null
            ? (int) $params['limit_hard'] : null;

        $this->validateColumnLimits($limitWarning, $limitHard);

        return DB::transaction(function () use ($project, $name, $limitWarning, $limitHard) {
            // Lock all rows of the project's columns to serialize concurrent creates.
            ScrumColumn::where('project_id', $project->id)->lockForUpdate()->get();

            $nextPosition = ((int) (ScrumColumn::where('project_id', $project->id)->max('position') ?? -1)) + 1;

            $column = ScrumColumn::create([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'name' => $name,
                'position' => $nextPosition,
                'limit_warning' => $limitWarning,
                'limit_hard' => $limitHard,
            ]);

            return $column->format();
        });
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function columnUpdate(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $column = $this->findColumnWithAccess((string) ($params['column_id'] ?? ''), $user);

        $hasName = array_key_exists('name', $params);
        $hasWarning = array_key_exists('limit_warning', $params);
        $hasHard = array_key_exists('limit_hard', $params);

        if (! $hasName && ! $hasWarning && ! $hasHard) {
            throw ValidationException::withMessages([
                'column' => ['At least one field must be provided.'],
            ]);
        }

        $data = [];

        if ($hasName) {
            $name = trim((string) $params['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => ['Column name is required.']]);
            }
            $data['name'] = $name;
        }

        $finalWarning = $hasWarning
            ? ($params['limit_warning'] === null ? null : (int) $params['limit_warning'])
            : $column->limit_warning;
        $finalHard = $hasHard
            ? ($params['limit_hard'] === null ? null : (int) $params['limit_hard'])
            : $column->limit_hard;

        $this->validateColumnLimits($finalWarning, $finalHard);

        if ($hasWarning) {
            $data['limit_warning'] = $finalWarning;
        }
        if ($hasHard) {
            $data['limit_hard'] = $finalHard;
        }

        DB::transaction(function () use ($column, $data) {
            ScrumColumn::whereKey($column->id)->lockForUpdate()->firstOrFail();
            if ($data !== []) {
                $column->update($data);
            }
        });

        return $column->refresh()->format();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function columnDelete(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $column = $this->findColumnWithAccess((string) ($params['column_id'] ?? ''), $user);

        return DB::transaction(function () use ($column) {
            /** @var ScrumColumn $locked */
            $locked = ScrumColumn::whereKey($column->id)->lockForUpdate()->firstOrFail();
            $count = $locked->placementCount();

            if ($count > 0) {
                throw ValidationException::withMessages([
                    'column' => ["Cannot delete column '{$locked->name}' because it contains {$count} placement(s). Move or remove the items first."],
                ]);
            }

            $deletedId = $locked->id;
            $locked->delete();

            return [
                'message' => 'Column deleted.',
                'deleted_column_id' => $deletedId,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function columnReorder(array $params, User $user): array
    {
        $this->assertCanManage($user);
        $project = $this->findProjectWithAccess((string) ($params['project_code'] ?? ''), $user);

        $raw = $params['column_ids'] ?? [];
        if (! is_array($raw) || $raw === []) {
            throw ValidationException::withMessages(['column_ids' => ['column_ids cannot be empty.']]);
        }
        foreach ($raw as $id) {
            if (! is_string($id)) {
                throw ValidationException::withMessages(['column_ids' => ['column_ids must be a list of strings.']]);
            }
        }
        /** @var array<int, string> $columnIds */
        $columnIds = array_values($raw);

        $duplicates = array_diff_assoc($columnIds, array_unique($columnIds));
        if ($duplicates !== []) {
            $first = (string) reset($duplicates);
            throw ValidationException::withMessages([
                'column_ids' => ["Duplicate column_id in column_ids: '{$first}'."],
            ]);
        }

        // Coverage check vs project's actual columns.
        $existing = ScrumColumn::where('project_id', $project->id)->pluck('id')->all();
        $missing = array_values(array_diff($existing, $columnIds));
        $unexpected = array_values(array_diff($columnIds, $existing));

        if ($missing !== [] || $unexpected !== []) {
            throw ValidationException::withMessages([
                'column_ids' => [
                    'Reorder coverage mismatch. Missing: ['
                    .implode(', ', $missing).']. Unexpected: ['
                    .implode(', ', $unexpected).'].',
                ],
            ]);
        }

        DB::transaction(function () use ($project, $columnIds) {
            ScrumColumn::where('project_id', $project->id)->lockForUpdate()->get();

            // Pass 1: assign transient negative positions to free the UNIQUE(project_id, position) slots.
            foreach ($columnIds as $index => $columnId) {
                ScrumColumn::where('id', $columnId)->update(['position' => -($index + 1)]);
            }
            // Pass 2: assign final 0..N-1 positions.
            foreach ($columnIds as $index => $columnId) {
                ScrumColumn::where('id', $columnId)->update(['position' => $index]);
            }
        });

        $columns = ScrumColumn::where('project_id', $project->id)
            ->orderBy('position')
            ->get();

        return [
            'message' => 'Columns reordered.',
            'count' => $columns->count(),
            'columns' => $columns->map(fn (ScrumColumn $c) => $c->format())->all(),
        ];
    }

    private function findColumnWithAccess(string $columnId, User $user): ScrumColumn
    {
        if ($columnId === '') {
            throw ValidationException::withMessages(['column' => ['Column not found.']]);
        }

        /** @var ScrumColumn|null $column */
        $column = ScrumColumn::find($columnId); // BelongsToTenant scope filters cross-tenant

        if ($column === null) {
            throw ValidationException::withMessages(['column' => ['Column not found.']]);
        }

        $isMember = ProjectMember::where('project_id', $column->project_id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages(['column' => ['Column not found.']]);
        }

        return $column;
    }

    // ===== POIESIS-13: Scrum Item Placement =====

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getItemPlaceToolDescription(): array
    {
        return [
            'name' => 'scrum_item_place',
            'description' => 'Place a sprint item into a Scrum board column. Fails if the item is already placed (use scrum_item_move). Refuses if the column has reached its hard WIP limit. Append by default; specify position for explicit insertion (others are shifted).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sprint_identifier' => ['type' => 'string', 'description' => 'Sprint identifier (e.g. PROJ-S1).'],
                    'artifact_identifier' => ['type' => 'string', 'description' => 'Artifact identifier (e.g. PROJ-42).'],
                    'column_id' => ['type' => 'string', 'description' => 'Column UUID (from scrum_board_get).'],
                    'position' => ['type' => 'integer', 'description' => 'Optional 0-indexed position. If absent or >= count, append at end.'],
                ],
                'required' => ['sprint_identifier', 'artifact_identifier', 'column_id'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getItemMoveToolDescription(): array
    {
        return [
            'name' => 'scrum_item_move',
            'description' => 'Move an already-placed item to another position (intra-column reorder) or another column (inter-column). Fails if the item is not placed (use scrum_item_place). Inter-column move respects hard WIP limit on the target.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'artifact_identifier' => ['type' => 'string'],
                    'column_id' => ['type' => 'string', 'description' => 'Target column UUID.'],
                    'position' => ['type' => 'integer', 'description' => 'Optional 0-indexed target position. If absent, append at end of target column.'],
                ],
                'required' => ['artifact_identifier', 'column_id'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getItemUnplaceToolDescription(): array
    {
        return [
            'name' => 'scrum_item_unplace',
            'description' => 'Remove an item from the Scrum board. The item remains in its sprint (only the placement is deleted). Source column positions are recompacted to 0..N-1.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'artifact_identifier' => ['type' => 'string'],
                ],
                'required' => ['artifact_identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getColumnItemsToolDescription(): array
    {
        return [
            'name' => 'scrum_column_items',
            'description' => 'List items placed in a column, ordered by position. Optional filter by sprint_identifier returns only items belonging to that sprint. Read-only — accessible to any project member.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'column_id' => ['type' => 'string'],
                    'sprint_identifier' => ['type' => 'string', 'description' => 'Optional sprint filter (e.g. PROJ-S1).'],
                ],
                'required' => ['column_id'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function itemPlace(array $params, User $user): array
    {
        $this->assertCanManage($user);

        $column = $this->findColumnWithAccess((string) ($params['column_id'] ?? ''), $user);

        $sprintIdentifier = (string) ($params['sprint_identifier'] ?? '');
        if ($sprintIdentifier === '') {
            throw ValidationException::withMessages(['sprint_identifier' => ['sprint_identifier is required.']]);
        }
        $artifactIdentifier = (string) ($params['artifact_identifier'] ?? '');
        if ($artifactIdentifier === '') {
            throw ValidationException::withMessages(['artifact_identifier' => ['artifact_identifier is required.']]);
        }

        $position = $this->normalizePlacementPosition($params['position'] ?? null, array_key_exists('position', $params));

        $sprintItem = $this->findSprintItemByArtifactIdentifier($artifactIdentifier, $sprintIdentifier, $user);

        if ($sprintItem->sprint->project_id !== $column->project_id) {
            throw ValidationException::withMessages(['column_id' => ['Item and column belong to different projects.']]);
        }

        if (ScrumItemPlacement::where('sprint_item_id', $sprintItem->id)->exists()) {
            throw ValidationException::withMessages([
                'artifact_identifier' => ['Item is already placed on the board. Use scrum_item_move instead.'],
            ]);
        }

        $placement = DB::transaction(function () use ($column, $sprintItem, $position) {
            ScrumItemPlacement::where('column_id', $column->id)->lockForUpdate()->get();

            $count = ScrumItemPlacement::where('column_id', $column->id)->count();

            if ($column->limit_hard !== null && $count >= $column->limit_hard) {
                throw ValidationException::withMessages([
                    'column_id' => ["Column '{$column->name}' has reached its hard limit ({$column->limit_hard}). Cannot place more items."],
                ]);
            }

            $effectivePosition = ($position === null || $position >= $count) ? $count : $position;

            if ($effectivePosition < $count) {
                $this->shiftPositionsForInsert($column->id, $effectivePosition);
            }

            return ScrumItemPlacement::create([
                'sprint_item_id' => $sprintItem->id,
                'column_id' => $column->id,
                'position' => $effectivePosition,
            ]);
        });

        $placement->load(['sprintItem.sprint', 'sprintItem.artifact.artifactable']);

        return [
            'message' => 'Item placed.',
            'placement' => $placement->format(),
            'warnings' => $this->computeColumnWarnings($column->refresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function itemMove(array $params, User $user): array
    {
        $this->assertCanManage($user);

        $column = $this->findColumnWithAccess((string) ($params['column_id'] ?? ''), $user);

        $artifactIdentifier = (string) ($params['artifact_identifier'] ?? '');
        if ($artifactIdentifier === '') {
            throw ValidationException::withMessages(['artifact_identifier' => ['artifact_identifier is required.']]);
        }

        $position = $this->normalizePlacementPosition($params['position'] ?? null, array_key_exists('position', $params));

        $placement = $this->findPlacementForArtifact($artifactIdentifier, $user);

        if ($placement->sprintItem->sprint->project_id !== $column->project_id) {
            throw ValidationException::withMessages(['column_id' => ['Item and column belong to different projects.']]);
        }

        $sourceColumnId = $placement->column_id;
        $isIntraColumn = ($sourceColumnId === $column->id);

        $updated = DB::transaction(function () use ($placement, $column, $sourceColumnId, $isIntraColumn, $position) {
            ScrumItemPlacement::where('column_id', $sourceColumnId)->lockForUpdate()->get();
            if (! $isIntraColumn) {
                ScrumItemPlacement::where('column_id', $column->id)->lockForUpdate()->get();
            }

            if ($isIntraColumn) {
                $count = ScrumItemPlacement::where('column_id', $column->id)->count();
                $oldPosition = $placement->position;
                $maxIndex = max(0, $count - 1);
                $target = ($position === null || $position >= $count) ? $maxIndex : $position;

                if ($target === $oldPosition) {
                    return $placement;
                }

                if ($target < $oldPosition) {
                    ScrumItemPlacement::where('column_id', $column->id)
                        ->where('position', '>=', $target)
                        ->where('position', '<', $oldPosition)
                        ->where('id', '!=', $placement->id)
                        ->orderByDesc('position')
                        ->each(fn (ScrumItemPlacement $p) => $p->update(['position' => $p->position + 1]));
                } else {
                    ScrumItemPlacement::where('column_id', $column->id)
                        ->where('position', '>', $oldPosition)
                        ->where('position', '<=', $target)
                        ->where('id', '!=', $placement->id)
                        ->orderBy('position')
                        ->each(fn (ScrumItemPlacement $p) => $p->update(['position' => $p->position - 1]));
                }
                $placement->update(['position' => $target]);

                return $placement;
            }

            // Inter-column.
            $targetCount = ScrumItemPlacement::where('column_id', $column->id)->count();
            if ($column->limit_hard !== null && $targetCount >= $column->limit_hard) {
                throw ValidationException::withMessages([
                    'column_id' => ["Column '{$column->name}' has reached its hard limit ({$column->limit_hard}). Cannot place more items."],
                ]);
            }

            $effectivePosition = ($position === null || $position >= $targetCount) ? $targetCount : $position;

            // Move to target column at an unsigned-safe sentinel position, recompact source,
            // then insert at target. MariaDB rejects -1 because position is unsigned.
            $placement->update(['column_id' => $column->id, 'position' => $targetCount]);
            $this->recompactColumnPositions($sourceColumnId);

            if ($effectivePosition < $targetCount) {
                $this->shiftPositionsForInsert($column->id, $effectivePosition);
            }
            $placement->update(['position' => $effectivePosition]);

            return $placement;
        });

        $updated->refresh()->load(['sprintItem.sprint', 'sprintItem.artifact.artifactable']);

        return [
            'message' => 'Item moved.',
            'placement' => $updated->format(),
            'from_column_id' => $isIntraColumn ? null : $sourceColumnId,
            'to_column_id' => $column->id,
            'warnings' => $this->computeColumnWarnings($column->refresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function itemUnplace(array $params, User $user): array
    {
        $this->assertCanManage($user);

        $artifactIdentifier = (string) ($params['artifact_identifier'] ?? '');
        if ($artifactIdentifier === '') {
            throw ValidationException::withMessages(['artifact_identifier' => ['artifact_identifier is required.']]);
        }

        $sprintItem = $this->findSprintItemByArtifactIdentifier($artifactIdentifier, null, $user);

        /** @var ScrumItemPlacement|null $placement */
        $placement = ScrumItemPlacement::where('sprint_item_id', $sprintItem->id)->first();
        if ($placement === null) {
            throw ValidationException::withMessages([
                'artifact_identifier' => ['Item is not placed on the board.'],
            ]);
        }

        $sourceColumnId = $placement->column_id;

        DB::transaction(function () use ($placement, $sourceColumnId) {
            ScrumItemPlacement::where('column_id', $sourceColumnId)->lockForUpdate()->get();
            $placement->delete();
            $this->recompactColumnPositions($sourceColumnId);
        });

        return [
            'message' => 'Item unplaced.',
            'artifact_identifier' => $artifactIdentifier,
            'from_column_id' => $sourceColumnId,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function columnItems(array $params, User $user): array
    {
        $column = $this->findColumnWithAccess((string) ($params['column_id'] ?? ''), $user);

        $sprintIdentifier = isset($params['sprint_identifier']) && $params['sprint_identifier'] !== ''
            ? (string) $params['sprint_identifier']
            : null;

        $sprint = null;
        if ($sprintIdentifier !== null) {
            $sprint = $this->resolveSprintByIdentifier($sprintIdentifier, $column->project_id, 'sprint_identifier');
        }

        $query = ScrumItemPlacement::where('column_id', $column->id)
            ->with(['sprintItem.sprint', 'sprintItem.artifact.artifactable'])
            ->orderBy('position');

        if ($sprint !== null) {
            $sprintId = $sprint->id;
            $query->whereHas('sprintItem', fn ($q) => $q->where('sprint_id', $sprintId));
        }

        $placements = $query->get()
            ->filter(fn (ScrumItemPlacement $placement) => $this->isReadyStoryPlacement($placement))
            ->values();
        $count = $placements->count();

        return [
            'column_id' => $column->id,
            'column_name' => $column->name,
            'sprint_identifier' => $sprintIdentifier,
            'count' => $count,
            'limit_warning' => $column->limit_warning,
            'limit_hard' => $column->limit_hard,
            'at_warning' => $column->limit_warning !== null && $count >= $column->limit_warning,
            'at_hard_limit' => $column->limit_hard !== null && $count >= $column->limit_hard,
            'items' => $placements->map(fn (ScrumItemPlacement $p) => $p->format())->all(),
        ];
    }

    private function isReadyStoryPlacement(ScrumItemPlacement $placement): bool
    {
        $artifactable = $placement->sprintItem->artifact?->artifactable;

        return $artifactable instanceof Story && $artifactable->ready === true;
    }

    private function findSprintItemByArtifactIdentifier(
        string $artifactIdentifier,
        ?string $sprintIdentifier,
        User $user,
    ): SprintItem {
        if ($artifactIdentifier === '') {
            throw ValidationException::withMessages(['artifact_identifier' => ['Item not found in any sprint.']]);
        }

        /** @var Artifact|null $artifact */
        $artifact = Artifact::where('identifier', $artifactIdentifier)->first();
        if ($artifact === null) {
            throw ValidationException::withMessages(['artifact_identifier' => ['Item not found in any sprint.']]);
        }

        $isMember = ProjectMember::where('project_id', $artifact->project_id)
            ->where('user_id', $user->id)
            ->exists();
        if (! $isMember) {
            throw ValidationException::withMessages(['artifact_identifier' => ['Item not found in any sprint.']]);
        }

        if ($sprintIdentifier !== null) {
            $sprint = $this->resolveSprintByIdentifier($sprintIdentifier, $artifact->project_id, 'artifact_identifier', "Item not found in sprint '{$sprintIdentifier}'.");

            /** @var SprintItem|null $sprintItem */
            $sprintItem = SprintItem::where('artifact_id', $artifact->id)
                ->where('sprint_id', $sprint->id)
                ->with('sprint')
                ->first();
            if ($sprintItem === null) {
                throw ValidationException::withMessages([
                    'artifact_identifier' => ["Item not found in sprint '{$sprintIdentifier}'."],
                ]);
            }

            return $sprintItem;
        }

        /** @var SprintItem|null $sprintItem */
        $sprintItem = SprintItem::where('artifact_id', $artifact->id)
            ->whereHas('sprint', fn ($q) => $q->where('project_id', $artifact->project_id))
            ->with('sprint')
            ->first();
        if ($sprintItem === null) {
            throw ValidationException::withMessages(['artifact_identifier' => ['Item not found in any sprint.']]);
        }

        return $sprintItem;
    }

    private function findPlacementForArtifact(string $artifactIdentifier, User $user): ScrumItemPlacement
    {
        $sprintItem = $this->findSprintItemByArtifactIdentifier($artifactIdentifier, null, $user);

        /** @var ScrumItemPlacement|null $placement */
        $placement = ScrumItemPlacement::where('sprint_item_id', $sprintItem->id)
            ->with(['sprintItem.sprint', 'sprintItem.artifact.artifactable'])
            ->first();

        if ($placement === null) {
            throw ValidationException::withMessages([
                'artifact_identifier' => ['Item is not placed on the board. Use scrum_item_place first.'],
            ]);
        }

        return $placement;
    }

    private function recompactColumnPositions(string $columnId): void
    {
        /** @var Collection<int, ScrumItemPlacement> $placements */
        $placements = ScrumItemPlacement::where('column_id', $columnId)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();

        foreach ($placements as $i => $p) {
            if ($p->position !== $i) {
                $p->position = $i;
                $p->save();
            }
        }
    }

    private function shiftPositionsForInsert(string $columnId, int $insertPosition): void
    {
        ScrumItemPlacement::where('column_id', $columnId)
            ->where('position', '>=', $insertPosition)
            ->orderByDesc('position')
            ->each(fn (ScrumItemPlacement $p) => $p->update(['position' => $p->position + 1]));
    }

    /** @return array<int, array<string, mixed>> */
    private function computeColumnWarnings(ScrumColumn $column): array
    {
        $count = $column->placementCount();
        if ($column->limit_warning === null || $count < $column->limit_warning) {
            return [];
        }

        return [[
            'type' => 'column_warning_limit',
            'column_id' => $column->id,
            'column_name' => $column->name,
            'count' => $count,
            'limit_warning' => $column->limit_warning,
        ]];
    }

    private function normalizePlacementPosition(mixed $value, bool $exists): ?int
    {
        if (! $exists || $value === null) {
            return null;
        }
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw ValidationException::withMessages(['position' => ['Position must be a non-negative integer.']]);
        }
        $int = (int) $value;
        if ($int < 0) {
            throw ValidationException::withMessages(['position' => ['Position must be a non-negative integer.']]);
        }

        return $int;
    }

    /**
     * Resolves a Sprint from its identifier string (PROJ-SN) scoped to a project.
     * Throws ValidationException with $errorKey if not found.
     */
    private function resolveSprintByIdentifier(
        string $identifier,
        string $projectId,
        string $errorKey,
        ?string $errorMessage = null,
    ): Sprint {
        if (! preg_match('/^([A-Z0-9]+)-S(\d+)$/', $identifier, $m)) {
            throw ValidationException::withMessages([
                $errorKey => [$errorMessage ?? "Sprint '{$identifier}' not found in project."],
            ]);
        }

        /** @var Sprint|null $sprint */
        $sprint = Sprint::where('project_id', $projectId)
            ->where('sprint_number', (int) $m[2])
            ->first();

        if ($sprint === null) {
            throw ValidationException::withMessages([
                $errorKey => [$errorMessage ?? "Sprint '{$identifier}' not found in project."],
            ]);
        }

        return $sprint;
    }
}
