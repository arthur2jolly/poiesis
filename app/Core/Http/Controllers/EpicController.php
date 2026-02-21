<?php

declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreEpicRequest;
use App\Core\Http\Requests\UpdateEpicRequest;
use App\Core\Http\Resources\EpicResource;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EpicController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $project = $request->attributes->get('project');
        $perPage = min((int) $request->input('per_page', 25), 100);

        $epics = $project->epics()
            ->withCount('stories')
            ->paginate($perPage);

        return response()->json([
            'data' => EpicResource::collection($epics),
            'meta' => [
                'current_page' => $epics->currentPage(),
                'per_page' => $epics->perPage(),
                'total' => $epics->total(),
                'last_page' => $epics->lastPage(),
            ],
        ]);
    }

    public function store(StoreEpicRequest $request): JsonResponse
    {
        $project = $request->attributes->get('project');

        $epic = $project->epics()->create($request->validated());

        return (new EpicResource($epic))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $code, string $identifier): JsonResponse
    {
        $epic = $this->resolveEpic($identifier);
        $epic->loadCount('stories');

        return (new EpicResource($epic))->response();
    }

    public function update(UpdateEpicRequest $request, string $code, string $identifier): JsonResponse
    {
        $epic = $this->resolveEpic($identifier);
        $epic->update($request->validated());

        return (new EpicResource($epic))->response();
    }

    public function destroy(Request $request, string $code, string $identifier): JsonResponse
    {
        $this->resolveEpic($identifier)->delete();

        return response()->json(null, 204);
    }

    private function resolveEpic(string $identifier): Epic
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Epic) {
            abort(404, 'Epic not found.');
        }

        return $model;
    }
}
