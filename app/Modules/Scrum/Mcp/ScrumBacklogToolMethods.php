<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ScrumBacklogToolMethods
{
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
}
