<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProjectMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');

        $perPage = min((int) $request->input('per_page', 25), 100);

        $members = ProjectMember::with('user:id,name')
            ->where('project_id', $project->id)
            ->paginate($perPage);

        return response()->json([
            'data' => $members->map(fn (ProjectMember $m) => [
                'id' => $m->id,
                'user' => ['id' => $m->user->id, 'name' => $m->user->name],
                'position' => $m->position,
                'created_at' => $m->created_at,
            ]),
            'meta' => [
                'current_page' => $members->currentPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
                'last_page' => $members->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');

        /** @var User $authUser */
        $authUser = $request->user();

        if (! $this->isOwner($project->id, $authUser->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'position' => ['required', 'string', 'in:'.implode(',', config('core.project_positions'))],
        ]);

        $alreadyMember = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $validated['user_id'])
            ->exists();

        if ($alreadyMember) {
            return response()->json(['message' => 'User is already a member of this project.'], 422);
        }

        $member = ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $validated['user_id'],
            'position' => $validated['position'],
        ]);

        $member->load('user:id,name');

        return response()->json([
            'id' => $member->id,
            'user' => ['id' => $member->user->id, 'name' => $member->user->name],
            'position' => $member->position,
            'created_at' => $member->created_at,
        ], 201);
    }

    public function update(Request $request, string $code, string $memberId): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');

        /** @var User $authUser */
        $authUser = $request->user();

        if (! $this->isOwner($project->id, $authUser->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $member = ProjectMember::where('id', $memberId)
            ->where('project_id', $project->id)
            ->first();

        if ($member === null) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $validated = $request->validate([
            'position' => ['required', 'string', 'in:'.implode(',', config('core.project_positions'))],
        ]);

        if ($validated['position'] !== 'owner' && ProjectMember::isLastOwner($project->id, $member->user_id)) {
            return response()->json(['message' => 'Cannot downgrade the last owner of the project.'], 422);
        }

        $member->update(['position' => $validated['position']]);
        $member->load('user:id,name');

        return response()->json([
            'id' => $member->id,
            'user' => ['id' => $member->user->id, 'name' => $member->user->name],
            'position' => $member->position,
            'created_at' => $member->created_at,
        ]);
    }

    public function destroy(Request $request, string $code, string $memberId): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');

        /** @var User $authUser */
        $authUser = $request->user();

        if (! $this->isOwner($project->id, $authUser->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $member = ProjectMember::where('id', $memberId)
            ->where('project_id', $project->id)
            ->first();

        if ($member === null) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        if (ProjectMember::isLastOwner($project->id, $member->user_id)) {
            return response()->json(['message' => 'Cannot remove the last owner of the project.'], 422);
        }

        $member->delete();

        return response()->json(null, 204);
    }

    private function isOwner(string $projectId, string $userId): bool
    {
        return ProjectMember::where('project_id', $projectId)
            ->where('user_id', $userId)
            ->where('position', 'owner')
            ->exists();
    }
}
