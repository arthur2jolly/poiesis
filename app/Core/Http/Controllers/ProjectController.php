<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreProjectRequest;
use App\Core\Http\Requests\UpdateProjectRequest;
use App\Core\Http\Resources\ProjectResource;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Core\Models\User $user */
        $user = $request->user();

        $perPage = min((int) $request->input('per_page', 25), 100);

        $projects = Project::accessibleBy($user)->paginate($perPage);

        return response()->json([
            'data' => ProjectResource::collection($projects),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
                'last_page' => $projects->lastPage(),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        /** @var \App\Core\Models\User $user */
        $user = $request->user();

        $project = Project::create($request->validated());

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'position' => 'owner',
        ]);

        return (new ProjectResource($project))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        /** @var \App\Core\Models\Project $project */
        $project = $request->attributes->get('project');

        return (new ProjectResource($project))->response();
    }

    public function update(UpdateProjectRequest $request, string $code): JsonResponse
    {
        /** @var \App\Core\Models\Project $project */
        $project = $request->attributes->get('project');

        $project->update($request->validated());

        return (new ProjectResource($project))->response();
    }

    public function destroy(Request $request, string $code): JsonResponse
    {
        /** @var \App\Core\Models\Project $project */
        $project = $request->attributes->get('project');

        /** @var \App\Core\Models\User $user */
        $user = $request->user();

        $isOwner = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('position', 'owner')
            ->exists();

        if (! $isOwner) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $project->delete();

        return response()->json(null, 204);
    }
}
