<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Http\Controllers;

use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DashboardController extends Controller
{
    public function projects(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $projects = Project::accessibleBy($user)
            ->withCount(['epics', 'tasks'])
            ->orderBy('titre')
            ->get();

        return view('dashboard::projects.index', compact('projects'));
    }

    public function projectOverview(Request $request, string $code): View
    {
        $project = $this->resolveProject($request, $code);

        $project->loadCount(['epics', 'tasks', 'standaloneTasks']);

        $epics = $project->epics()
            ->withCount('stories')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $openStoriesCount = Story::whereHas('epic', fn ($q) => $q->where('project_id', $project->id))
            ->where('statut', 'open')
            ->count();

        $members = $project->members()->with('user')->get();

        return view('dashboard::project.overview', compact(
            'project', 'epics', 'openStoriesCount', 'members'
        ));
    }

    public function epics(Request $request, string $code): View
    {
        $project = $this->resolveProject($request, $code);

        $epics = $project->epics()
            ->withCount('stories')
            ->orderByDesc('created_at')
            ->get();

        return view('dashboard::project.epics', compact('project', 'epics'));
    }

    public function epic(Request $request, string $code, string $identifier): View
    {
        $project = $this->resolveProject($request, $code);

        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Epic || $model->project_id !== $project->id) {
            throw new NotFoundHttpException;
        }

        $model->load(['stories' => fn ($q) => $q->withCount('tasks')->orderBy('ordre')]);

        return view('dashboard::project.epic', [
            'project' => $project,
            'epic' => $model,
        ]);
    }

    public function stories(Request $request, string $code): View
    {
        $project = $this->resolveProject($request, $code);

        $filters = $request->only(['statut', 'priorite', 'type', 'nature']);

        $stories = Story::whereHas('epic', fn ($q) => $q->where('project_id', $project->id))
            ->with('epic')
            ->withCount('tasks')
            ->filter($filters)
            ->orderByDesc('created_at')
            ->get();

        return view('dashboard::project.stories', compact('project', 'stories', 'filters'));
    }

    public function story(Request $request, string $code, string $identifier): View
    {
        $project = $this->resolveProject($request, $code);

        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Story) {
            throw new NotFoundHttpException;
        }

        $epicProject = $model->epic->project_id;
        if ($epicProject !== $project->id) {
            throw new NotFoundHttpException;
        }

        $model->load(['epic', 'tasks' => fn ($q) => $q->orderBy('ordre')]);

        return view('dashboard::project.story', [
            'project' => $project,
            'story' => $model,
        ]);
    }

    public function tasks(Request $request, string $code): View
    {
        $project = $this->resolveProject($request, $code);

        $filters = $request->only(['statut', 'priorite', 'type', 'nature']);

        $tasks = Task::where('project_id', $project->id)
            ->with('story')
            ->filter($filters)
            ->orderByDesc('created_at')
            ->get();

        return view('dashboard::project.tasks', compact('project', 'tasks', 'filters'));
    }

    public function task(Request $request, string $code, string $identifier): View
    {
        $project = $this->resolveProject($request, $code);

        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Task || $model->project_id !== $project->id) {
            throw new NotFoundHttpException;
        }

        $model->load('story.epic');

        return view('dashboard::project.task', [
            'project' => $project,
            'task' => $model,
        ]);
    }

    private function resolveProject(Request $request, string $code): Project
    {
        /** @var User $user */
        $user = $request->user();

        $project = Project::accessibleBy($user)->where('code', $code)->first();

        if ($project === null) {
            abort(404);
        }

        return $project;
    }
}
