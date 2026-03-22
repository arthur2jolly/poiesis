<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\Project;
use App\Core\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProjectAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->route('code');

        if ($code === null) {
            return response()->json(['message' => 'Project code is required.'], 400);
        }

        $project = Project::where('code', $code)->first();

        if ($project === null) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        /** @var User $user */
        $user = $request->user();

        $isMember = $project->users()
            ->where('users.id', $user->id)
            ->exists();

        if (! $isMember) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->attributes->set('project', $project);

        return $next($request);
    }
}
