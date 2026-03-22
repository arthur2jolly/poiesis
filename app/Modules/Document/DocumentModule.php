<?php

declare(strict_types=1);

namespace App\Modules\Document;

use App\Core\Contracts\ModuleInterface;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Modules\Document\Mcp\DocumentTools;

class DocumentModule implements ModuleInterface
{
    public function slug(): string
    {
        return 'document';
    }

    public function name(): string
    {
        return 'Documents';
    }

    public function description(): string
    {
        return 'Attach reference documents to projects, with paginated content for LLM consumption';
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
        return [new DocumentTools];
    }
}
