<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\User;
use App\Core\Services\DependencyService;
use App\Core\Support\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoryTools implements McpToolInterface
{
    /**
     * Returns the list of tool definitions provided by this class.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return [
            $this->getListStoriesToolDescription(),
            $this->getUpdateStoryToolDescription(),
            $this->getDeleteStoryToolDescription(),
            $this->getUpdateStoryStatusToolDescription(),
            $this->getGetStoryToolDescription(),
            $this->getCreateStoryToolDescription(),
            $this->getCreateStoriesToolDescription(),
            $this->getListEpicStoriesToolDescription(),
            $this->getStartStoryDescription(),
            $this->getUnstartStoryDescription(),
        ];
    }

    /**
     * Dispatches execution to the appropriate private method.
     *
     * @param  string  $toolName  Name of the tool to execute.
     * @param  array<string, mixed>  $params  Input arguments provided by the caller.
     * @param  User  $user  Authenticated user performing the action.
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the tool name is not handled by this class.
     * @throws ValidationException On invalid input or access denial.
     * @throws ModelNotFoundException When the project does not exist.
     */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'list_stories' => $this->listStories($params, $user),
            'list_epic_stories' => $this->listEpicStories($params, $user),
            'get_story' => $this->getStory($params, $user),
            'create_story' => $this->createStory($params, $user),
            'create_stories' => $this->createStories($params, $user),
            'update_story' => $this->updateStory($params, $user),
            'delete_story' => $this->deleteStory($params, $user),
            'update_story_status' => $this->updateStoryStatus($params, $user),
            'start_story' => $this->startStory($params, $user),
            'unstart_story' => $this->unstartStory($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    private function getListStoriesToolDescription(): array
    {
        return [
            'name' => 'list_stories',
            'description' => 'List all stories in a project with optional filters',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => config('core.item_types')],
                    'nature' => ['type' => 'string', 'enum' => config('core.work_natures')],
                    'statut' => ['type' => 'string', 'enum' => config('core.statuts')],
                    'priorite' => ['type' => 'string', 'enum' => config('core.priorities')],
                    'tags' => ['type' => 'string', 'description' => 'Comma-separated tags'],
                    'q' => ['type' => 'string', 'description' => 'Text search in title and description'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    private function getListEpicStoriesToolDescription(): array
    {
        return [
            'name' => 'list_epic_stories',
            'description' => 'List stories of a specific epic',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'epic_identifier' => ['type' => 'string'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ],
                'required' => ['project_code', 'epic_identifier'],
            ],
        ];
    }

    private function getGetStoryToolDescription(): array
    {
        return [
            'name' => 'get_story',
            'description' => 'Get story details by its identifier',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function getCreateStoryToolDescription(): array
    {
        return [
            'name' => 'create_story',
            'description' => 'Create a story in an epic',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'epic_identifier' => ['type' => 'string'],
                    'titre' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => config('core.item_types')],
                    'description' => ['type' => 'string'],
                    'nature' => ['type' => 'string', 'enum' => config('core.work_natures')],
                    'priorite' => ['type' => 'string', 'enum' => config('core.priorities')],
                    'ordre' => ['type' => 'integer'],
                    'story_points' => ['type' => 'integer'],
                    'reference_doc' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['project_code', 'epic_identifier', 'titre', 'type'],
            ],
        ];
    }

    private function getCreateStoriesToolDescription(): array
    {
        return [
            'name' => 'create_stories',
            'description' => 'Create multiple stories in one operation (atomic)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'epic_identifier' => ['type' => 'string'],
                    'stories' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'titre' => ['type' => 'string'],
                                'type' => ['type' => 'string', 'enum' => config('core.item_types')],
                                'description' => ['type' => 'string'],
                                'nature' => ['type' => 'string', 'enum' => config('core.work_natures')],
                                'priorite' => ['type' => 'string', 'enum' => config('core.priorities')],
                                'ordre' => ['type' => 'integer'],
                                'story_points' => ['type' => 'integer'],
                                'reference_doc' => ['type' => 'string'],
                                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['titre', 'type'],
                        ],
                    ],
                ],
                'required' => ['project_code', 'epic_identifier', 'stories'],
            ],
        ];
    }

    private function getUpdateStoryToolDescription(): array
    {
        return [
            'name' => 'update_story',
            'description' => 'Update a story',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'titre' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => config('core.item_types')],
                    'nature' => ['type' => 'string', 'enum' => config('core.work_natures')],
                    'priorite' => ['type' => 'string', 'enum' => config('core.priorities')],
                    'ordre' => ['type' => 'integer'],
                    'story_points' => ['type' => 'integer'],
                    'reference_doc' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function getDeleteStoryToolDescription(): array
    {
        return [
            'name' => 'delete_story',
            'description' => 'Delete a story and its child tasks',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function getUpdateStoryStatusToolDescription(): array
    {
        return [
            'name' => 'update_story_status',
            'description' => 'Change the status of a story (draft->open->closed, closed->open). Side-effect (POIESIS-107): the first transition to `open` auto-fills started_at = now() if it was null. Subsequent transitions never overwrite or clear started_at — use unstart_story to reset.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'statut' => ['type' => 'string'],
                ],
                'required' => ['identifier', 'statut'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getStartStoryDescription(): array
    {
        return [
            'name' => 'start_story',
            'description' => 'Mark a story as started by setting started_at = now(). Idempotent: a second call leaves the original timestamp untouched. Does NOT change the story statut. Stable error key: story.cannot_start_closed when statut is closed. Useful to flag a story as in-progress while still in draft (e.g. analysis phase) without going through update_story_status.',
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
    private function getUnstartStoryDescription(): array
    {
        return [
            'name' => 'unstart_story',
            'description' => 'Clear started_at on a story (back to null). Idempotent. Does NOT change the story statut. Use to correct a wrongly-started story or to reset the WIP indicator.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function listStories(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $perPage = min((int) ($params['per_page'] ?? 25), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $query = Story::whereHas('epic', fn ($q) => $q->where('project_id', $project->id))
            ->filter($params)
            ->with('artifact')
            ->withCount('tasks');

        $stories = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $stories->map(fn (Story $s) => $s->format())->all(),
            'meta' => [
                'current_page' => $stories->currentPage(),
                'per_page' => $stories->perPage(),
                'total' => $stories->total(),
                'last_page' => $stories->lastPage(),
            ],
        ];
    }

    private function listEpicStories(array $params, User $user): array
    {
        $this->findProjectWithAccess($params['project_code'], $user);
        $epic = $this->resolveEpic($params['epic_identifier']);

        $perPage = min((int) ($params['per_page'] ?? 25), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $stories = $epic->stories()->with('artifact')->withCount('tasks')
            ->orderBy('ordre')->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $stories->map(fn (Story $s) => $s->format())->all(),
            'meta' => [
                'current_page' => $stories->currentPage(),
                'per_page' => $stories->perPage(),
                'total' => $stories->total(),
                'last_page' => $stories->lastPage(),
            ],
        ];
    }

    private function getStory(array $params, User $user): array
    {
        $story = $this->resolveStory($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $story->epic->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $story->loadCount('tasks');

        $deps = app(DependencyService::class)->getDependencies($story);

        $result = $story->format();
        $result['blocked_by'] = array_map(fn ($m) => $m->identifier, $deps['blocked_by']);
        $result['blocks'] = array_map(fn ($m) => $m->identifier, $deps['blocks']);

        return $result;
    }

    private function createStory(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'story' => ['You do not have permission to create stories.'],
            ]);
        }

        $this->findProjectWithAccess($params['project_code'], $user);
        $epic = $this->resolveEpic($params['epic_identifier']);
        $this->validateStoryData($params);

        $story = Story::create([
            'epic_id' => $epic->id,
            'titre' => $params['titre'],
            'type' => $params['type'],
            'description' => $params['description'] ?? null,
            'nature' => $params['nature'] ?? null,
            'priorite' => $params['priorite'] ?? config('core.default_priority'),
            'statut' => config('core.default_statut'),
            'ordre' => $params['ordre'] ?? null,
            'story_points' => $params['story_points'] ?? null,
            'reference_doc' => $params['reference_doc'] ?? null,
            'tags' => $params['tags'] ?? null,
        ]);

        $story->load('artifact');
        $story->loadCount('tasks');

        return $story->format();
    }

    private function createStories(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'stories' => ['You do not have permission to create stories.'],
            ]);
        }

        $this->findProjectWithAccess($params['project_code'], $user);
        $epic = $this->resolveEpic($params['epic_identifier']);
        $storiesData = $params['stories'] ?? [];

        // Validate all before creating
        foreach ($storiesData as $index => $storyData) {
            try {
                $this->validateStoryData($storyData);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    "stories.{$index}" => $e->errors(),
                ]);
            }
        }

        $created = DB::transaction(function () use ($epic, $storiesData) {
            $results = [];
            foreach ($storiesData as $data) {
                $story = Story::create([
                    'epic_id' => $epic->id,
                    'titre' => $data['titre'],
                    'type' => $data['type'],
                    'description' => $data['description'] ?? null,
                    'nature' => $data['nature'] ?? null,
                    'priorite' => $data['priorite'] ?? config('core.default_priority'),
                    'statut' => config('core.default_statut'),
                    'ordre' => $data['ordre'] ?? null,
                    'story_points' => $data['story_points'] ?? null,
                    'reference_doc' => $data['reference_doc'] ?? null,
                    'tags' => $data['tags'] ?? null,
                ]);
                $story->load('artifact');
                $story->loadCount('tasks');
                $results[] = $story->format();
            }

            return $results;
        });

        return ['data' => $created];
    }

    private function updateStory(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'story' => ['You do not have permission to update stories.'],
            ]);
        }

        $story = $this->resolveStory($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $story->epic->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $updatable = ['titre', 'description', 'type', 'nature', 'priorite', 'ordre', 'story_points', 'reference_doc', 'tags'];
        $data = [];
        foreach ($updatable as $field) {
            if (array_key_exists($field, $params)) {
                $data[$field] = $params[$field];
            }
        }

        if (isset($data['type']) && ! in_array($data['type'], config('core.item_types'), true)) {
            throw ValidationException::withMessages(['type' => ['Invalid type.']]);
        }
        if (isset($data['nature']) && $data['nature'] !== null && ! in_array($data['nature'], config('core.work_natures'), true)) {
            throw ValidationException::withMessages(['nature' => ['Invalid nature.']]);
        }
        if (isset($data['priorite']) && ! in_array($data['priorite'], config('core.priorities'), true)) {
            throw ValidationException::withMessages(['priorite' => ['Invalid priority.']]);
        }

        if (! empty($data)) {
            $story->update($data);
        }

        $story->loadCount('tasks');

        return $story->format();
    }

    private function deleteStory(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'story' => ['You do not have permission to delete stories.'],
            ]);
        }

        $story = $this->resolveStory($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $story->epic->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $story->delete();

        return ['message' => 'Story deleted.'];
    }

    private function updateStoryStatus(array $params, User $user): array
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'story' => ['You do not have permission to update stories.'],
            ]);
        }

        $story = $this->resolveStory($params['identifier']);

        // Check project access
        if (! ProjectMember::where('project_id', $story->epic->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $story->transitionStatus($params['statut']);
        $story->loadCount('tasks');

        return $story->format();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function startStory(array $params, User $user): array
    {
        $story = $this->loadStoryForStartedToggle((string) ($params['identifier'] ?? ''), $user);

        if ($story->statut === 'closed') {
            throw ValidationException::withMessages([
                'story.cannot_start_closed' => ["[story.cannot_start_closed] Cannot start story '{$story->identifier}': statut is 'closed'."],
            ]);
        }

        if ($story->started_at === null) {
            $story->started_at = \Carbon\Carbon::now();
            $story->save();
        }

        $story->loadCount('tasks');

        return $story->format();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function unstartStory(array $params, User $user): array
    {
        $story = $this->loadStoryForStartedToggle((string) ($params['identifier'] ?? ''), $user);

        if ($story->started_at !== null) {
            $story->started_at = null;
            $story->save();
        }

        $story->loadCount('tasks');

        return $story->format();
    }

    private function loadStoryForStartedToggle(string $identifier, User $user): Story
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'story' => ['You do not have permission to update stories.'],
            ]);
        }

        $story = $this->resolveStory($identifier);

        if (! ProjectMember::where('project_id', $story->epic->project_id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        return $story;
    }

    private function validateStoryData(array $data): void
    {
        if (empty($data['titre'])) {
            throw ValidationException::withMessages(['titre' => ['The titre field is required.']]);
        }
        if (empty($data['type']) || ! in_array($data['type'], config('core.item_types'), true)) {
            throw ValidationException::withMessages(['type' => ['The selected type is invalid.']]);
        }
        if (! empty($data['nature']) && ! in_array($data['nature'], config('core.work_natures'), true)) {
            throw ValidationException::withMessages(['nature' => ['The selected nature is invalid.']]);
        }
        if (! empty($data['priorite']) && ! in_array($data['priorite'], config('core.priorities'), true)) {
            throw ValidationException::withMessages(['priorite' => ['The selected priority is invalid.']]);
        }
    }

    private function resolveStory(string $identifier): Story
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Story) {
            throw ValidationException::withMessages([
                'identifier' => ["'{$identifier}' is not a story."],
            ]);
        }

        return $model;
    }

    private function resolveEpic(string $identifier): Epic
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Epic) {
            throw ValidationException::withMessages([
                'identifier' => ["'{$identifier}' is not an epic."],
            ]);
        }

        return $model;
    }

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        if (! ProjectMember::where('project_id', $project->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        return $project;
    }
}
