<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\ProjectMember;
use App\Core\Module\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
    ) {}

    public function available(): JsonResponse
    {
        $modules = collect($this->registry->all())->map(fn ($module, $slug) => [
            'slug' => $module->slug(),
            'name' => $module->name(),
            'description' => $module->description(),
            'dependencies' => $module->dependencies(),
        ])->values();

        return response()->json(['data' => $modules]);
    }

    public function active(Request $request, string $code): JsonResponse
    {
        $project = $request->attributes->get('project');

        return response()->json(['data' => $project->modules ?? []]);
    }

    public function activate(Request $request, string $code): JsonResponse
    {
        $project = $request->attributes->get('project');
        $user = $request->user();

        if (! $this->isOwner($project->id, $user->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate(['slug' => ['required', 'string']]);
        $slug = $validated['slug'];

        if (! $this->registry->isRegistered($slug)) {
            return response()->json(['message' => "Module '{$slug}' is not registered."], 422);
        }

        $activeModules = $project->modules ?? [];

        if (in_array($slug, $activeModules, true)) {
            return response()->json(['message' => "Module '{$slug}' is already active."], 422);
        }

        // Check dependency satisfaction
        $missingDeps = array_diff(
            $this->registry->getDependenciesFor($slug),
            $activeModules
        );

        if ($missingDeps !== []) {
            $missing = implode("', '", $missingDeps);

            return response()->json([
                'message' => "Module '{$slug}' requires module '{$missing}' to be active first.",
            ], 422);
        }

        $activeModules[] = $slug;
        $project->modules = $activeModules;
        $project->save();

        return response()->json(['data' => $project->modules], 201);
    }

    public function deactivate(Request $request, string $code, string $slug): JsonResponse
    {
        $project = $request->attributes->get('project');
        $user = $request->user();

        if (! $this->isOwner($project->id, $user->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $activeModules = $project->modules ?? [];

        if (! in_array($slug, $activeModules, true)) {
            return response()->json(['message' => "Module '{$slug}' is not active."], 404);
        }

        // Check for active dependents
        $dependents = $this->registry->getDependentsOf($slug);
        $activeDependents = array_intersect($dependents, $activeModules);

        if ($activeDependents !== []) {
            $deps = implode("', '", $activeDependents);

            return response()->json([
                'message' => "Cannot deactivate '{$slug}'. The following modules depend on it: ['{$deps}'].",
            ], 422);
        }

        $project->modules = array_values(array_filter($activeModules, fn ($m) => $m !== $slug));
        $project->save();

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
