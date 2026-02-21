<?php

declare(strict_types=1);

namespace App\Core\Mcp\Contracts;

use App\Core\Models\User;

interface McpToolInterface
{
    /** @return array<int, array{name: string, description: string, inputSchema: array}> */
    public function tools(): array;

    public function execute(string $toolName, array $params, User $user): mixed;
}
