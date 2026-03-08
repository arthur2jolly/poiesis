<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Core\Module\ModuleRegistry;
use Illuminate\Validation\ValidationException;

class ModuleTools implements McpToolInterface
{
    public function __construct(
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * Returns the list of tool definitions provided by this class.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return [
            $this->getListAvailableModulesDescription(),
            $this->getListProjectModulesDescription(),
            $this->getActivateModuleDescription(),
            $this->getDeactivateModuleDescription(),
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
     * @throws \Illuminate\Validation\ValidationException On invalid input, access denial, or dependency conflict.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When the project does not exist.
     */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'list_available_modules' => $this->listAvailable(),
            'list_project_modules' => $this->listProjectModules($params, $user),
            'activate_module' => $this->activateModule($params, $user),
            'deactivate_module' => $this->deactivateModule($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    private function getListAvailableModulesDescription(): array
    {
        return [
            'name' => 'list_available_modules',
            'description' => 'List all available modules on the platform',
            'inputSchema' => ['type' => 'object'],
        ];
    }

    private function getListProjectModulesDescription(): array
    {
        return [
            'name' => 'list_project_modules',
            'description' => 'List active modules for a project',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    private function getActivateModuleDescription(): array
    {
        return [
            'name' => 'activate_module',
            'description' => 'Activate a module for a project (owner only)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'slug' => ['type' => 'string', 'description' => 'Module slug'],
                ],
                'required' => ['project_code', 'slug'],
            ],
        ];
    }

    private function getDeactivateModuleDescription(): array
    {
        return [
            'name' => 'deactivate_module',
            'description' => 'Deactivate a module for a project (owner only)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                ],
                'required' => ['project_code', 'slug'],
            ],
        ];
    }

    private function listAvailable(): array
    {
        $modules = collect($this->registry->all())->map(fn ($module, $slug) => [
            'slug' => $module->slug(),
            'name' => $module->name(),
            'description' => $module->description(),
            'dependencies' => $module->dependencies(),
        ])->values()->all();

        return ['data' => $modules];
    }

    private function listProjectModules(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);

        return ['data' => $project->modules ?? []];
    }

    private function activateModule(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $this->ensureOwner($project, $user);

        $slug = $params['slug'];

        if (! $this->registry->isRegistered($slug)) {
            throw ValidationException::withMessages([
                'slug' => ["Module '{$slug}' is not registered."],
            ]);
        }

        $activeModules = $project->modules ?? [];

        if (in_array($slug, $activeModules, true)) {
            throw ValidationException::withMessages([
                'slug' => ["Module '{$slug}' is already active."],
            ]);
        }

        // Check dependency satisfaction
        $missingDeps = array_diff(
            $this->registry->getDependenciesFor($slug),
            $activeModules
        );

        if ($missingDeps !== []) {
            $missing = implode("', '", $missingDeps);
            throw ValidationException::withMessages([
                'slug' => ["Module '{$slug}' requires module '{$missing}' to be active first."],
            ]);
        }

        $activeModules[] = $slug;
        $project->modules = $activeModules;
        $project->save();

        return ['data' => $project->modules];
    }

    private function deactivateModule(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $this->ensureOwner($project, $user);

        $slug = $params['slug'];
        $activeModules = $project->modules ?? [];

        if (! in_array($slug, $activeModules, true)) {
            throw ValidationException::withMessages([
                'slug' => ["Module '{$slug}' is not active."],
            ]);
        }

        // Check for active dependents
        $dependents = $this->registry->getDependentsOf($slug);
        $activeDependents = array_intersect($dependents, $activeModules);

        if ($activeDependents !== []) {
            $deps = implode("', '", $activeDependents);
            throw ValidationException::withMessages([
                'slug' => ["Cannot deactivate '{$slug}'. The following modules depend on it: ['{$deps}']."],
            ]);
        }

        $project->modules = array_values(array_filter($activeModules, fn ($m) => $m !== $slug));
        $project->save();

        return ['message' => "Module '{$slug}' deactivated."];
    }

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        if (! ProjectMember::where('project_id', $project->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        return $project;
    }

    private function ensureOwner(Project $project, User $user): void
    {
        $isOwner = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('position', 'owner')
            ->exists();

        if (! $isOwner) {
            throw ValidationException::withMessages([
                'project' => ['Only a project owner can manage modules.'],
            ]);
        }
    }
}
