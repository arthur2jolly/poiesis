<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreTaskRequest;
use App\Core\Http\Requests\UpdateTaskRequest;
use App\Core\Http\Resources\TaskResource;
use App\Core\Models\Artifact;
use App\Core\Models\Story;
use App\Core\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TaskController extends Controller
{
    public function index(Request $request, string $code): JsonResponse
    {
        $project = $request->attributes->get('project');
        $perPage = min((int) $request->input('per_page', 25), 100);

        $tasks = Task::where('project_id', $project->id)
            ->filter($request->only('type', 'nature', 'statut', 'priorite', 'tags', 'q'))
            ->paginate($perPage);

        return $this->paginatedResponse($tasks);
    }

    public function indexByStory(Request $request, string $code, string $identifier): JsonResponse
    {
        $story = $this->resolveStory($identifier);
        $perPage = min((int) $request->input('per_page', 25), 100);

        $tasks = $story->tasks()
            ->filter($request->only('type', 'nature', 'statut', 'priorite', 'tags', 'q'))
            ->paginate($perPage);

        return $this->paginatedResponse($tasks);
    }

    public function storeStandalone(StoreTaskRequest $request, string $code): JsonResponse
    {
        $project = $request->attributes->get('project');

        $data = $request->validated();
        $data['project_id'] = $project->id;
        $data['priorite'] ??= config('core.default_priority');
        $data['statut'] = config('core.default_statut');

        $task = Task::create($data);

        return (new TaskResource($task->load('project')))->response()->setStatusCode(201);
    }

    public function storeChild(StoreTaskRequest $request, string $code, string $identifier): JsonResponse
    {
        $story = $this->resolveStory($identifier);

        $data = $request->validated();
        $data['project_id'] = $story->epic->project_id;
        $data['story_id'] = $story->id;
        $data['priorite'] ??= config('core.default_priority');
        $data['statut'] = config('core.default_statut');

        $task = Task::create($data);

        return (new TaskResource($task->load('project', 'story')))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $code, string $identifier): JsonResponse
    {
        $task = $this->resolveTask($identifier);
        $task->load('project', 'story');

        return (new TaskResource($task))->response();
    }

    public function update(UpdateTaskRequest $request, string $code, string $identifier): JsonResponse
    {
        $task = $this->resolveTask($identifier);
        $task->update($request->validated());
        $task->load('project', 'story');

        return (new TaskResource($task))->response();
    }

    public function destroy(Request $request, string $code, string $identifier): JsonResponse
    {
        $this->resolveTask($identifier)->delete();

        return response()->json(null, 204);
    }

    public function transition(Request $request, string $code, string $identifier): JsonResponse
    {
        $validated = $request->validate(['statut' => ['required', 'string']]);
        $task = $this->resolveTask($identifier);
        $task->transitionStatus($validated['statut']);
        $task->load('project', 'story');

        return (new TaskResource($task))->response();
    }

    public function batchStoreStandalone(Request $request, string $code): JsonResponse
    {
        $project = $request->attributes->get('project');

        return $this->batchCreate($request, $project->id, null);
    }

    public function batchStoreChild(Request $request, string $code, string $identifier): JsonResponse
    {
        $story = $this->resolveStory($identifier);

        return $this->batchCreate($request, $story->epic->project_id, $story->id);
    }

    private function batchCreate(Request $request, string $projectId, ?string $storyId): JsonResponse
    {
        $request->validate(['tasks' => ['required', 'array', 'min:1']]);

        $rules = (new StoreTaskRequest)->rules();
        $prefixedRules = [];
        foreach ($rules as $field => $fieldRules) {
            $prefixedRules["tasks.*.{$field}"] = $fieldRules;
        }

        $validator = Validator::make($request->all(), $prefixedRules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tasks = DB::transaction(function () use ($request, $projectId, $storyId) {
            $created = [];
            foreach ($request->input('tasks') as $taskData) {
                $taskData['project_id'] = $projectId;
                $taskData['story_id'] = $storyId;
                $taskData['priorite'] ??= config('core.default_priority');
                $taskData['statut'] = config('core.default_statut');
                $created[] = Task::create($taskData);
            }

            return $created;
        });

        return response()->json([
            'data' => TaskResource::collection(collect($tasks)->each(fn ($t) => $t->load('project', 'story'))),
        ], 201);
    }

    private function resolveTask(string $identifier): Task
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Task) {
            abort(404, 'Task not found.');
        }

        return $model;
    }

    private function resolveStory(string $identifier): Story
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Story) {
            abort(404, 'Story not found.');
        }

        return $model;
    }

    private function paginatedResponse($paginator): JsonResponse
    {
        return response()->json([
            'data' => TaskResource::collection($paginator),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
