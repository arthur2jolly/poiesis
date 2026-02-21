<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Resources\ArtifactSearchResource;
use App\Core\Http\Resources\EpicResource;
use App\Core\Http\Resources\StoryResource;
use App\Core\Http\Resources\TaskResource;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Story;
use App\Core\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ArtifactController extends Controller
{
    public function resolve(string $identifier): JsonResponse
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model) {
            abort(404, 'Artifact not found.');
        }

        $resource = match (true) {
            $model instanceof Epic => new EpicResource($model->loadCount('stories')),
            $model instanceof Story => new StoryResource($model->loadCount('tasks')->load('epic.artifact')),
            $model instanceof Task => new TaskResource($model->load('project', 'story')),
        };

        return $resource->response();
    }

    public function search(Request $request, string $code): JsonResponse
    {
        $q = $request->input('q');

        if (empty($q)) {
            return response()->json([
                'message' => 'The q parameter is required.',
            ], 422);
        }

        $project = $request->attributes->get('project');
        $perPage = min((int) $request->input('per_page', 25), 100);

        $results = Artifact::searchInProject($project, $q);

        $page = (int) $request->input('page', 1);
        $sliced = $results->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => ArtifactSearchResource::collection($sliced),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $results->count(),
                'last_page' => max(1, (int) ceil($results->count() / $perPage)),
            ],
        ]);
    }
}
