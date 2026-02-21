<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Artifact;
use App\Core\Models\User;
use App\Core\Services\DependencyService;
use Illuminate\Validation\ValidationException;

class DependencyTools implements McpToolInterface
{
    public function __construct(
        private readonly DependencyService $dependencyService,
    ) {}

    /**
     * Returns the list of tool definitions provided by this class.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return [
            $this->getAddDependencyDescription(),
            $this->getRemoveDependencyDescription(),
            $this->getListDependenciesDescription(),
        ];
    }

    /**
     * Dispatches execution to the appropriate private method.
     *
     * @param  string  $toolName  Name of the tool to execute.
     * @param  array<string, mixed>  $params  Input arguments provided by the caller.
     * @param  User  $user  Authenticated user performing the action (unused — dependencies are not scoped per user).
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the tool name is not handled by this class.
     * @throws \Illuminate\Validation\ValidationException When an identifier cannot be resolved.
     */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'add_dependency' => $this->addDependency($params),
            'remove_dependency' => $this->removeDependency($params),
            'list_dependencies' => $this->listDependencies($params),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    private function getAddDependencyDescription(): array
    {
        return [
            'name' => 'add_dependency',
            'description' => 'Declare that an item depends on another (blocked_by)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'blocked_identifier' => ['type' => 'string', 'description' => 'Identifier of the blocked item'],
                    'blocking_identifier' => ['type' => 'string', 'description' => 'Identifier of the blocking item'],
                ],
                'required' => ['blocked_identifier', 'blocking_identifier'],
            ],
        ];
    }

    private function getRemoveDependencyDescription(): array
    {
        return [
            'name' => 'remove_dependency',
            'description' => 'Remove a dependency between two items',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'blocked_identifier' => ['type' => 'string'],
                    'blocking_identifier' => ['type' => 'string'],
                ],
                'required' => ['blocked_identifier', 'blocking_identifier'],
            ],
        ];
    }

    private function getListDependenciesDescription(): array
    {
        return [
            'name' => 'list_dependencies',
            'description' => 'List dependencies of an item (blocks + blocked_by)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    private function addDependency(array $params): array
    {
        $blocked = $this->resolveItem($params['blocked_identifier']);
        $blocking = $this->resolveItem($params['blocking_identifier']);

        $this->dependencyService->addDependency($blocked, $blocking);

        return ['message' => "{$params['blocked_identifier']} is now blocked by {$params['blocking_identifier']}."];
    }

    private function removeDependency(array $params): array
    {
        $blocked = $this->resolveItem($params['blocked_identifier']);
        $blocking = $this->resolveItem($params['blocking_identifier']);

        $this->dependencyService->removeDependency($blocked, $blocking);

        return ['message' => 'Dependency removed.'];
    }

    private function listDependencies(array $params): array
    {
        $item = $this->resolveItem($params['identifier']);
        $deps = $this->dependencyService->getDependencies($item);

        return [
            'identifier' => $params['identifier'],
            'blocked_by' => array_map(fn ($m) => [
                'identifier' => $m->identifier,
                'titre' => $m->titre,
            ], $deps['blocked_by']),
            'blocks' => array_map(fn ($m) => [
                'identifier' => $m->identifier,
                'titre' => $m->titre,
            ], $deps['blocks']),
        ];
    }

    private function resolveItem(string $identifier): \Illuminate\Database\Eloquent\Model
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model) {
            throw ValidationException::withMessages([
                'identifier' => ["Item '{$identifier}' not found."],
            ]);
        }

        return $model;
    }
}
