<?php

declare(strict_types=1);

namespace App\Modules\Example;

use App\Core\Contracts\ModuleInterface;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Modules\Example\Mcp\ExampleTools;

class ExampleModule implements ModuleInterface
{
    public function slug(): string
    {
        return 'example';
    }

    public function name(): string
    {
        return 'Example Module';
    }

    public function description(): string
    {
        return 'A skeleton module for demonstration';
    }

    /** @return array<int, string> */
    public function dependencies(): array
    {
        return [];
    }

    public function registerRoutes(): void
    {
        // No routes for the example module
    }

    public function registerListeners(): void
    {
        // No listeners for the example module
    }

    public function migrationPath(): string
    {
        return '';
    }

    /** @return array<int, McpToolInterface> */
    public function mcpTools(): array
    {
        return [new ExampleTools];
    }
}
