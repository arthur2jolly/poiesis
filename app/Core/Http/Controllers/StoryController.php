<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreStoryRequest;
use App\Core\Http\Requests\UpdateStoryRequest;
use App\Core\Http\Resources\StoryResource;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StoryController extends Controller
{
    public function index(Request $request, string $code): JsonResponse
    {
        $project = $request->attributes->get('project');
        $perPage = min((int) $request->input('per_page', 25), 100);

        $stories = Story::whereHas('epic', fn ($q) => $q->where('project_id', $project->id))
            ->filter($request->only('type', 'nature', 'statut', 'priorite', 'tags', 'q'))
            ->withCount('tasks')
            ->paginate($perPage);

        return $this->paginatedResponse($stories);
    }

    public function indexByEpic(Request $request, string $code, string $identifier): JsonResponse
    {
        $epic = $this->resolveEpic($identifier);
        $perPage = min((int) $request->input('per_page', 25), 100);

        $stories = $epic->stories()
            ->filter($request->only('type', 'nature', 'statut', 'priorite', 'tags', 'q'))
            ->withCount('tasks')
            ->paginate($perPage);

        return $this->paginatedResponse($stories);
    }

    public function store(StoreStoryRequest $request, string $code, string $identifier): JsonResponse
    {
        $epic = $this->resolveEpic($identifier);

        $data = $request->validated();
        $data['epic_id'] = $epic->id;
        $data['priorite'] ??= config('core.default_priority');
        $data['statut'] = config('core.default_statut');

        $story = Story::create($data);

        return (new StoryResource($story->load('epic.artifact')))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $code, string $identifier): JsonResponse
    {
        $story = $this->resolveStory($identifier);
        $story->loadCount('tasks')->load('epic.artifact');

        return (new StoryResource($story))->response();
    }

    public function update(UpdateStoryRequest $request, string $code, string $identifier): JsonResponse
    {
        $story = $this->resolveStory($identifier);
        $story->update($request->validated());
        $story->load('epic.artifact');

        return (new StoryResource($story))->response();
    }

    public function destroy(Request $request, string $code, string $identifier): JsonResponse
    {
        $this->resolveStory($identifier)->delete();

        return response()->json(null, 204);
    }

    public function transition(Request $request, string $code, string $identifier): JsonResponse
    {
        $validated = $request->validate(['statut' => ['required', 'string']]);
        $story = $this->resolveStory($identifier);
        $story->transitionStatus($validated['statut']);
        $story->load('epic.artifact');

        return (new StoryResource($story))->response();
    }

    public function batchStore(Request $request, string $code, string $identifier): JsonResponse
    {
        $epic = $this->resolveEpic($identifier);

        $request->validate(['stories' => ['required', 'array', 'min:1']]);

        $rules = (new StoreStoryRequest)->rules();
        $prefixedRules = [];
        foreach ($rules as $field => $fieldRules) {
            $prefixedRules["stories.*.{$field}"] = $fieldRules;
        }

        $validator = Validator::make($request->all(), $prefixedRules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $stories = DB::transaction(function () use ($request, $epic) {
            $created = [];
            foreach ($request->input('stories') as $storyData) {
                $storyData['epic_id'] = $epic->id;
                $storyData['priorite'] ??= config('core.default_priority');
                $storyData['statut'] = config('core.default_statut');
                $created[] = Story::create($storyData);
            }

            return $created;
        });

        return response()->json([
            'data' => StoryResource::collection(collect($stories)->each(fn ($s) => $s->load('epic.artifact'))),
        ], 201);
    }

    private function resolveStory(string $identifier): Story
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Story) {
            abort(404, 'Story not found.');
        }

        return $model;
    }

    private function resolveEpic(string $identifier): Epic
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Epic) {
            abort(404, 'Epic not found.');
        }

        return $model;
    }

    private function paginatedResponse($paginator): JsonResponse
    {
        return response()->json([
            'data' => StoryResource::collection($paginator),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
