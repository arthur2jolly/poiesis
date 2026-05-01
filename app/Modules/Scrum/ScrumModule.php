<?php

declare(strict_types=1);

namespace App\Modules\Scrum;

use App\Core\Contracts\ModuleInterface;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Modules\Scrum\Mcp\ScrumTools;

class ScrumModule implements ModuleInterface
{
    public function slug(): string
    {
        return 'scrum';
    }

    public function name(): string
    {
        return 'Scrum';
    }

    public function description(): string
    {
        return 'Sprint planning, backlog management and capacity tracking for Scrum-style iterations';
    }

    /** @return array<int, string> */
    public function dependencies(): array
    {
        return [];
    }

    public function registerRoutes(): void {}

    public function registerListeners(): void {}

    public function migrationPath(): string
    {
        return __DIR__.'/Database/Migrations';
    }

    /** @return array<int, McpToolInterface> */
    public function mcpTools(): array
    {
        return [new ScrumTools];
    }
}
