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
use App\Core\Support\Role;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Carbon\Carbon;
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

        $artifactable = $this->resolveSprintItemArtifact(
            (string) ($params['item_identifier'] ?? ''),
            $sprint->project_id
        );

        $position = null;
        if (array_key_exists('position', $params) && $params['position'] !== null) {
            if (! is_int($params['position']) || $params['position'] < 0) {
                throw ValidationException::withMessages([
                    'position' => ['Position must be a non-negative integer.'],
                ]);
            }
            $position = $params['position'];
        }

        return DB::transaction(function () use ($sprint, $artifactable, $position) {
            Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            /** @var Artifact $artifactRow */
            $artifactRow = Artifact::where('artifactable_id', $artifactable->getKey())
                ->where('artifactable_type', $artifactable->getMorphClass())
                ->firstOrFail();

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

        $artifactable = Artifact::resolveIdentifier((string) ($params['item_identifier'] ?? ''));
        if ($artifactable === null) {
            throw ValidationException::withMessages(['item' => ['Item not found.']]);
        }

        /** @var Artifact|null $artifactRow */
        $artifactRow = Artifact::where('artifactable_id', $artifactable->getKey())
            ->where('artifactable_type', $artifactable->getMorphClass())
            ->first();

        if (! $artifactRow instanceof Artifact) {
            throw ValidationException::withMessages(['item' => ['Item not found.']]);
        }

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
    private function resolveSprintItemArtifact(string $identifier, string $projectId): Model
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

        if ($itemProjectId !== $projectId) {
            throw ValidationException::withMessages([
                'item' => ['Item does not belong to the same project as the sprint.'],
            ]);
        }

        return $model;
    }

    // ===== Backlog =====

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function backlogList(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess((string) ($params['project_code'] ?? ''), $user);

        if (array_key_exists('status', $params) && $params['status'] !== null
            && ! in_array($params['status'], config('core.statuts'), true)) {
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

        $query = Story::whereHas('epic', fn ($q) => $q->where('project_id', $project->id))->with('epic');

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

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getAddToSprintToolDescription(): array
    {
        return [
            'name' => 'add_to_sprint',
            'description' => 'Add a story or standalone task to a sprint backlog (sprint must be in status planned or active)',
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
                    'status' => ['type' => 'string', 'enum' => config('core.statuts'), 'description' => 'Filter by story status'],
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
}
