<?php

declare(strict_types=1);

namespace App\Core\Mcp\Contracts;

use App\Core\Models\User;

interface McpResourceInterface
{
    public function uri(): string;

    public function name(): string;

    public function description(): string;

    public function read(array $params, User $user): array;
}
