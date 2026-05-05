<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Models\Artifact;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Services\DependencyService;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ScrumPlanningAndValidationToolMethods
{
    /**
     * Single source of truth for the "plannable" predicate.
     *
     * A story is plannable when its statut is 'open' and ready=true.
     * Both planningStart() (snapshot query) and validateStoriesForPlanning()
     * (per-story validator) MUST honour these constants so the snapshot and
     * the manual add path can never diverge for any combination — including
     * the pathological draft+ready=true case (POIESIS-54).
     */
    private const PLANNABLE_STATUS = 'open';

    private const PLANNABLE_REQUIRES_READY = true;

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
            ->where('statut', self::PLANNABLE_STATUS)
            ->where('ready', self::PLANNABLE_REQUIRES_READY)
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

        return $this->computeSprintValidation($sprint);
    }

    /**
     * Pure plan-validation logic, callable both from the MCP tool
     * sprintValidatePlan() and from sprintCommit() without re-routing
     * through a fake $params payload.
     *
     * @return array{
     *     ok: bool,
     *     sprint_identifier: string,
     *     errors: array<int, array<string, mixed>>,
     *     warnings: array<int, array<string, mixed>>,
     *     summary: array{items_count: int, engaged_points: int, capacity: int|null}
     * }
     */
    private function computeSprintValidation(Sprint $sprint): array
    {
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
                $gateViolation = $this->storyPlanningGateViolation($model);
                if ($gateViolation !== null) {
                    $violations[] = "{$id}: {$gateViolation}";

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

    /**
     * Per-story implementation of the plannable predicate, mirror of the
     * planningStart() query. Returns null if the story passes the gate, or
     * a violation message suitable for the add_to_planning error output.
     *
     * Single source of truth for the criteria via PLANNABLE_STATUS and
     * PLANNABLE_REQUIRES_READY (POIESIS-54).
     */
    private function storyPlanningGateViolation(Story $story): ?string
    {
        if ($story->statut === 'closed') {
            return 'cannot plan a closed story.';
        }
        if ($story->statut !== self::PLANNABLE_STATUS) {
            return 'must be open to plan.';
        }
        if (self::PLANNABLE_REQUIRES_READY && $story->ready !== true) {
            $missing = $this->dorMissingFields($story);

            return 'not ready (missing: '.implode(', ', $missing).').';
        }

        return null;
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
}
