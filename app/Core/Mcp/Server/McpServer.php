<?php

declare(strict_types=1);

namespace App\Core\Mcp\Server;

use App\Core\Mcp\Contracts\McpPromptInterface;
use App\Core\Mcp\Contracts\McpResourceInterface;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Project;
use App\Core\Models\User;

class McpServer
{
    private const PROTOCOL_VERSION = '2025-03-26';

    /** @var array<int, McpToolInterface> */
    private array $coreTools = [];

    /** @var array<string, array<int, McpToolInterface>> */
    private array $moduleTools = [];

    /** @var array<int, McpResourceInterface> */
    private array $coreResources = [];

    /** @var array<int, McpPromptInterface> */
    private array $prompts = [];

    public function registerCoreTools(McpToolInterface $tools): void
    {
        $this->coreTools[] = $tools;
    }

    public function registerModuleTools(string $moduleSlug, array $toolProviders): void
    {
        $this->moduleTools[$moduleSlug] = $toolProviders;
    }

    public function registerCoreResource(McpResourceInterface $resource): void
    {
        $this->coreResources[] = $resource;
    }

    public function registerPrompt(McpPromptInterface $prompt): void
    {
        $this->prompts[] = $prompt;
    }

    /**
     * @return array|null null signals a notification (HTTP 202, no body)
     */
    public function handleRequest(array $jsonRpc, User $user): ?array
    {
        $method = $jsonRpc['method'] ?? '';
        $params = $jsonRpc['params'] ?? [];
        $id = $jsonRpc['id'] ?? null;

        // Notifications (no id) must return HTTP 202 with no body per MCP spec
        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->handleInitialize($params, $id),
            'tools/list' => $this->handleToolsList($params, $user, $id),
            'tools/call' => $this->handleToolsCall($params, $user, $id),
            'resources/list' => $this->handleResourcesList($id),
            'resources/read' => $this->handleResourcesRead($params, $user, $id),
            'prompts/list' => $this->handlePromptsList($id),
            'prompts/get' => $this->handlePromptsGet($params, $id),
            default => (new McpTransport)->encodeError(-32601, "Method not found: {$method}", $id),
        };
    }

    /** @return array<int, array{name: string, description: string, inputSchema: array}> */
    public function resolveTools(?Project $project): array
    {
        $tools = [];

        foreach ($this->coreTools as $provider) {
            $tools = array_merge($tools, $provider->tools());
        }

        if ($project) {
            $activeModules = $project->modules ?? [];
            foreach ($this->moduleTools as $slug => $providers) {
                if (in_array($slug, $activeModules, true)) {
                    foreach ($providers as $provider) {
                        $tools = array_merge($tools, $provider->tools());
                    }
                }
            }
        }

        return $tools;
    }

    private function handleInitialize(array $params, string|int|null $id): array
    {
        $transport = new McpTransport;

        return $transport->encodeResponse([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'Poiesis',
                'version' => '1.0.0',
            ],
        ], $id);
    }

    private function handleToolsList(array $params, User $user, string|int|null $id): array
    {
        $project = $this->resolveProjectFromParams($params);
        $tools = $this->resolveTools($project);

        return (new McpTransport)->encodeResponse(['tools' => $tools], $id);
    }

    private function handleToolsCall(array $params, User $user, string|int|null $id): array
    {
        $transport = new McpTransport;
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        // Search in core tools first
        foreach ($this->coreTools as $provider) {
            foreach ($provider->tools() as $tool) {
                if ($tool['name'] === $toolName) {
                    return $this->executeToolSafely($provider, $toolName, $arguments, $user, $id);
                }
            }
        }

        // Search in module tools
        foreach ($this->moduleTools as $slug => $providers) {
            foreach ($providers as $provider) {
                foreach ($provider->tools() as $tool) {
                    if ($tool['name'] === $toolName) {
                        // Check module activation
                        $projectCode = $arguments['project_code'] ?? null;
                        if ($projectCode) {
                            $project = Project::where('code', $projectCode)->first();
                            if ($project && ! in_array($slug, $project->modules ?? [], true)) {
                                return $transport->encodeError(
                                    -32000,
                                    "Module '{$slug}' is not active for project '{$projectCode}'.",
                                    $id
                                );
                            }
                        }

                        return $this->executeToolSafely($provider, $toolName, $arguments, $user, $id);
                    }
                }
            }
        }

        return $transport->encodeError(-32001, "Tool not found: {$toolName}", $id);
    }

    private function executeToolSafely(McpToolInterface $provider, string $toolName, array $arguments, User $user, string|int|null $id): array
    {
        $transport = new McpTransport;

        try {
            $result = $provider->execute($toolName, $arguments, $user);

            return $transport->encodeResponse([
                'content' => [
                    ['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                ],
            ], $id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $transport->encodeError(-32602, $e->getMessage(), $id, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $transport->encodeError(-32602, 'Resource not found.', $id);
        } catch (\Throwable $e) {
            return $transport->encodeError(-32603, $e->getMessage(), $id);
        }
    }

    private function handleResourcesList(string|int|null $id): array
    {
        $resources = array_map(fn (McpResourceInterface $r) => [
            'uri' => $r->uri(),
            'name' => $r->name(),
            'description' => $r->description(),
        ], $this->coreResources);

        return (new McpTransport)->encodeResponse(['resources' => array_values($resources)], $id);
    }

    private function handleResourcesRead(array $params, User $user, string|int|null $id): array
    {
        $transport = new McpTransport;
        $uri = $params['uri'] ?? '';

        foreach ($this->coreResources as $resource) {
            if ($this->matchesResourceUri($resource->uri(), $uri, $extractedParams)) {
                try {
                    $result = $resource->read(array_merge($params, $extractedParams), $user);

                    return $transport->encodeResponse([
                        'contents' => [
                            ['uri' => $uri, 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                        ],
                    ], $id);
                } catch (\Throwable $e) {
                    return $transport->encodeError(-32603, $e->getMessage(), $id);
                }
            }
        }

        return $transport->encodeError(-32602, "Resource not found: {$uri}", $id);
    }

    private function matchesResourceUri(string $pattern, string $uri, ?array &$extracted = null): bool
    {
        $extracted = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', function ($matches) {
            return '(?P<'.$matches[1].'>[^\/]+)';
        }, preg_quote($pattern, '#'));

        $regex = str_replace(preg_quote('(?P<', '#'), '(?P<', $regex);
        $regex = str_replace(preg_quote('>[^\/]+)', '#'), '>[^\/]+)', $regex);

        // Simpler approach: direct pattern replacement
        $regexPattern = '#^'.preg_replace('/\\\{(\w+)\\\}/', '(?P<$1>[^\/]+)', preg_quote($pattern, '#')).'$#';

        if (preg_match($regexPattern, $uri, $matches)) {
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $extracted[$key] = $value;
                }
            }

            return true;
        }

        return false;
    }

    private function resolveProjectFromParams(array $params): ?Project
    {
        $code = $params['project_code'] ?? null;
        if (! $code) {
            return null;
        }

        return Project::where('code', $code)->first();
    }

    private function handlePromptsList(string|int|null $id): array
    {
        $prompts = array_map(fn (McpPromptInterface $p) => [
            'name' => $p->name(),
            'description' => $p->description(),
        ], $this->prompts);

        return (new McpTransport)->encodeResponse(['prompts' => array_values($prompts)], $id);
    }

    private function handlePromptsGet(array $params, string|int|null $id): array
    {
        $transport = new McpTransport;
        $name = $params['name'] ?? '';

        foreach ($this->prompts as $prompt) {
            if ($prompt->name() === $name) {
                return $transport->encodeResponse([
                    'description' => $prompt->description(),
                    'messages' => $prompt->messages(),
                ], $id);
            }
        }

        return $transport->encodeError(-32602, "Prompt not found: {$name}", $id);
    }
}
