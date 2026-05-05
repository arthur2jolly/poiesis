<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Models\Artifact;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\User;
use App\Modules\Scrum\Models\ScrumColumn;
use App\Modules\Scrum\Models\ScrumItemPlacement;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ScrumItemPlacementToolMethods
{
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
