<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\User;

class ScrumTools implements McpToolInterface
{
    /** @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function tools(): array
    {
        return [];
    }

    /** @param array<string, mixed> $params */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        throw new \InvalidArgumentException("Unknown tool: {$toolName}");
    }
}
