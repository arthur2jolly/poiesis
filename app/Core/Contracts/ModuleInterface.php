<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Mcp\Contracts\McpToolInterface;

interface ModuleInterface
{
    public function slug(): string;

    public function name(): string;

    public function description(): string;

    /** @return array<int, string> */
    public function dependencies(): array;

    public function registerRoutes(): void;

    public function registerListeners(): void;

    public function migrationPath(): string;

    /** @return array<int, McpToolInterface> */
    public function mcpTools(): array;
}
