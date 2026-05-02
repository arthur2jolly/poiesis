<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Http\Controllers;

use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Modules\Scrum\Models\ScrumColumn;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ScrumController extends Controller
{
    public function sprints(Request $request, string $code): View
    {
        $project = $this->project($request);
        $status = $request->string('status')->toString();

        $sprints = Sprint::where('project_id', $project->id)
            ->when($status !== '', fn (Builder $q) => $q->where('status', $status))
            ->withCount('items')
            ->orderBy('start_date', 'desc')
            ->orderBy('sprint_number', 'desc')
            ->get();

        return view('scrum::sprints.index', [
            'project' => $project,
            'sprints' => $sprints,
            'status' => $status,
            'statuses' => config('core.sprint_statuses', []),
        ]);
    }

    public function sprint(Request $request, string $code, string $identifier): View
    {
        $project = $this->project($request);
        $sprint = $this->resolveSprint($project, $identifier);
        $sprint->load(['items.artifact.artifactable'])->loadCount('items');

        return view('scrum::sprints.show', [
            'project' => $project,
            'sprint' => $sprint,
            'items' => $sprint->items->map(fn (SprintItem $item) => $this->formatSprintItem($item)),
        ]);
    }

    public function backlog(Request $request, string $code): View
    {
        $project = $this->project($request);
        $filters = [
            'statut' => $request->string('statut')->toString(),
            'priorite' => $request->string('priorite')->toString(),
            'epic' => $request->string('epic')->toString(),
            'ready' => $request->string('ready')->toString(),
            'in_sprint' => $request->string('in_sprint')->toString(),
        ];

        $storiesInSprints = DB::table('scrum_sprint_items')
            ->join('scrum_sprints', 'scrum_sprints.id', '=', 'scrum_sprint_items.sprint_id')
            ->join('artifacts', 'artifacts.id', '=', 'scrum_sprint_items.artifact_id')
            ->whereIn('scrum_sprints.status', ['planned', 'active'])
            ->where('scrum_sprints.project_id', $project->id)
            ->where('artifacts.artifactable_type', Story::class)
            ->pluck('artifacts.artifactable_id')
            ->all();

        $stories = Story::whereHas('epic', fn (Builder $q) => $q->where('project_id', $project->id))
            ->with('epic')
            ->when($filters['statut'] !== '', fn (Builder $q) => $q->where('statut', $filters['statut']))
            ->when($filters['priorite'] !== '', fn (Builder $q) => $q->where('priorite', $filters['priorite']))
            ->when($filters['epic'] !== '', function (Builder $q) use ($project, $filters): void {
                $epic = $this->resolveEpic($project, $filters['epic']);
                $q->where('epic_id', $epic->id);
            })
            ->when($filters['ready'] === 'yes', fn (Builder $q) => $q->where('ready', true))
            ->when($filters['ready'] === 'no', fn (Builder $q) => $q->where('ready', false))
            ->when($filters['in_sprint'] === 'yes', fn (Builder $q) => $q->whereIn('id', $storiesInSprints))
            ->when($filters['in_sprint'] === 'no', fn (Builder $q) => $q->whereNotIn('id', $storiesInSprints))
            ->orderByRaw('(rank IS NULL), rank ASC, created_at ASC')
            ->get();

        $epics = Epic::where('project_id', $project->id)->orderBy('created_at')->get();

        return view('scrum::backlog.index', [
            'project' => $project,
            'stories' => $stories,
            'epics' => $epics,
            'filters' => $filters,
            'storiesInSprints' => $storiesInSprints,
        ]);
    }

    public function activeBoard(Request $request, string $code): View
    {
        $project = $this->project($request);
        $sprint = Sprint::where('project_id', $project->id)
            ->where('status', 'active')
            ->orderByDesc('sprint_number')
            ->first();

        return $this->renderBoard($project, $sprint);
    }

    public function board(Request $request, string $code, string $sprint_identifier): View
    {
        $project = $this->project($request);

        return $this->renderBoard($project, $this->resolveSprint($project, $sprint_identifier));
    }

    private function renderBoard(Project $project, ?Sprint $sprint): View
    {
        $columns = ScrumColumn::where('project_id', $project->id)
            ->with(['placements.sprintItem.sprint', 'placements.sprintItem.artifact.artifactable'])
            ->withCount('placements')
            ->orderBy('position')
            ->get();

        $sprints = Sprint::where('project_id', $project->id)
            ->orderByDesc('sprint_number')
            ->get();

        return view('scrum::board.show', [
            'project' => $project,
            'sprint' => $sprint,
            'sprints' => $sprints,
            'columns' => $columns,
        ]);
    }

    private function project(Request $request): Project
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');

        return $project;
    }

    private function resolveSprint(Project $project, string $identifier): Sprint
    {
        if (! preg_match('/^'.preg_quote($project->code, '/').'-S(\d+)$/', $identifier, $matches)) {
            throw new NotFoundHttpException;
        }

        $sprint = Sprint::where('project_id', $project->id)
            ->where('sprint_number', (int) $matches[1])
            ->first();

        if (! $sprint instanceof Sprint) {
            throw new NotFoundHttpException;
        }

        return $sprint;
    }

    private function resolveEpic(Project $project, string $identifier): Epic
    {
        $model = Artifact::resolveIdentifier($identifier);
        if (! $model instanceof Epic || $model->project_id !== $project->id) {
            throw new NotFoundHttpException;
        }

        return $model;
    }

    /** @return array<string, mixed> */
    private function formatSprintItem(SprintItem $item): array
    {
        $artifactable = $item->artifact?->artifactable;

        return [
            'identifier' => $item->artifact?->identifier,
            'position' => $item->position,
            'title' => $artifactable instanceof Story || $artifactable instanceof Task ? $artifactable->titre : null,
            'kind' => $artifactable instanceof Story ? 'Story' : ($artifactable instanceof Task ? 'Task' : 'Item'),
            'status' => $artifactable instanceof Story || $artifactable instanceof Task ? $artifactable->statut : null,
            'points' => $artifactable instanceof Story ? $artifactable->story_points : null,
            'ready' => $artifactable instanceof Story ? $artifactable->ready : null,
        ];
    }
}
