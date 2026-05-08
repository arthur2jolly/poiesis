<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

trait ScrumToolDescriptionMethods
{
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
    private function getCommitSprintToolDescription(): array
    {
        return [
            'name' => 'commit_sprint',
            'description' => 'Commit a sprint plan (planned -> active). Internally runs validate_sprint_plan. Returns one of three shapes: (1) success { sprint, warnings, placed_count } when validation passes (or warnings acknowledged via force=true) — placed_count counts sprint items auto-placed in the first board column on this commit (POIESIS-106), warnings may include commit.no_board_columns or commit.column_wip_exceeded; (2) soft-fail { state: "warnings_pending", warnings, sprint_identifier } when warnings are present and force=false — no transition is performed, reissue with force=true to confirm; (3) ValidationException with one of the stable keys: commit.sprint_not_planned, commit.has_errors, commit.another_active. Hard errors are never bypassed by force.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'force' => ['type' => 'boolean', 'description' => 'Acknowledge non-blocking validation warnings and proceed. Default false. Never bypasses blocking errors.'],
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
            'description' => 'Diagnose a sprint before commit. Read-only. Returns { ok, errors[], warnings[], summary } covering: empty_sprint (error), missing_estimation (error, per story), blocking_dependency (error, per unresolved dependency), over_capacity (warning), missing_goal (warning). commit_sprint runs the same checks internally — call this directly only when you want a dry-run snapshot. Works on any sprint status.',
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
}
