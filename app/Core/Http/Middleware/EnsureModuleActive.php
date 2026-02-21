<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleActive
{
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        /** @var Project|null $project */
        $project = $request->attributes->get('project');

        if ($project === null) {
            return response()->json(['message' => 'Project context is required.'], 400);
        }

        if (! in_array($slug, $project->modules ?? [], true)) {
            return response()->json([
                'message' => "Module '{$slug}' is not active for this project.",
            ], 404);
        }

        return $next($request);
    }
}
