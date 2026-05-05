<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Modules\Scrum\Models\ScrumColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ScrumColumnToolMethods
{
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
}
