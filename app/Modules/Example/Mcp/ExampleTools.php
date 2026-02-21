<?php

declare(strict_types=1);

namespace App\Modules\Example\Mcp;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\User;

class ExampleTools implements McpToolInterface
{
    /** @return array<int, array{name: string, description: string, inputSchema: array}> */
    public function tools(): array
    {
        return [
            [
                'name' => 'ping',
                'description' => 'A simple ping tool that returns pong',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_code' => ['type' => 'string', 'description' => 'Project code'],
                    ],
                    'required' => ['project_code'],
                ],
            ],
        ];
    }

    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'ping' => ['message' => 'pong'],
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }
}
