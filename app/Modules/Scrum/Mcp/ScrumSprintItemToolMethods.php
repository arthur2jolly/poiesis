<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ScrumSprintItemToolMethods
{
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
}
