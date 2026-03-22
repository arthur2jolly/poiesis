<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class ArtifactTools implements McpToolInterface
{
    /**
     * Returns the list of tool definitions provided by this class.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return [
            $this->getResolveArtifactDescription(),
            $this->getSearchArtifactsDescription(),
        ];
    }

    /**
     * Dispatches execution to the appropriate private method.
     *
     * @param  string  $toolName  Name of the tool to execute.
     * @param  array<string, mixed>  $params  Input arguments provided by the caller.
     * @param  User  $user  Authenticated user performing the action.
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the tool name is not handled by this class.
     * @throws ValidationException On invalid input or access denial.
     * @throws ModelNotFoundException When the project does not exist.
     */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'resolve_artifact' => $this->resolveArtifact($params, $user),
            'search_artifacts' => $this->searchArtifacts($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    private function getResolveArtifactDescription(): array
    {
        return [
            'name' => 'resolve_artifact',
            'description' => 'Resolve an identifier (e.g. PROJ-3) to the complete entity',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string', 'description' => 'Artifact identifier'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function getSearchArtifactsDescription(): array
    {
        return [
            'name' => 'search_artifacts',
            'description' => 'Search items by keyword in a project',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'q' => ['type' => 'string', 'description' => 'Search keyword'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ],
                'required' => ['project_code', 'q'],
            ],
        ];
    }

    private function resolveArtifact(array $params, User $user): array
    {
        $model = Artifact::resolveIdentifier($params['identifier']);

        if (! $model) {
            throw ValidationException::withMessages([
                'identifier' => ["Identifier '{$params['identifier']}' not found."],
            ]);
        }

        // Determine project ID based on artifact type
        $projectId = match (true) {
            $model instanceof Epic => $model->project_id,
            $model instanceof Story => $model->epic->project_id,
            $model instanceof Task => $model->project_id,
            default => null,
        };

        // Check project access
        if ($projectId && ! ProjectMember::where('project_id', $projectId)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $type = match (true) {
            $model instanceof Epic => 'epic',
            $model instanceof Story => 'story',
            $model instanceof Task => 'task',
            default => 'unknown',
        };

        if ($model instanceof Epic) {
            $model->loadCount('stories');
        } elseif ($model instanceof Story) {
            $model->loadCount('tasks');
        }

        return ['type' => $type] + $model->format();
    }

    private function searchArtifacts(array $params, User $user): array
    {
        $project = Project::where('code', $params['project_code'])->firstOrFail();

        if (! ProjectMember::where('project_id', $project->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        $items = Artifact::searchInProject($project, $params['q']);

        $results = $items->map(function ($item) {
            $type = match (true) {
                $item instanceof Epic => 'epic',
                $item instanceof Story => 'story',
                $item instanceof Task => 'task',
                default => 'unknown',
            };

            return [
                'type' => $type,
                'identifier' => $item->identifier,
                'titre' => $item->titre,
            ];
        })->all();

        return ['data' => array_values($results)];
    }
}
