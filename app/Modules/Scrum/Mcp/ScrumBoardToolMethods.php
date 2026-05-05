<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Models\Project;
use App\Core\Models\User;
use App\Modules\Scrum\Models\ScrumColumn;
use App\Modules\Scrum\Models\ScrumItemPlacement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ScrumBoardToolMethods
{
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
}
