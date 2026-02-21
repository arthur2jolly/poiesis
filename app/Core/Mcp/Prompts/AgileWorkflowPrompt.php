<?php

declare(strict_types=1);

namespace App\Core\Mcp\Prompts;

use App\Core\Mcp\Contracts\McpPromptInterface;

class AgileWorkflowPrompt implements McpPromptInterface
{
    public function name(): string
    {
        return 'agile-workflow';
    }

    public function description(): string
    {
        return 'Poiesis agile workflow guide — hierarchy, statuses, and best practices';
    }

    public function messages(): array
    {
        return [
            [
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => $this->guide(),
                ],
            ],
        ];
    }

    private function guide(): string
    {
        return file_get_contents(resource_path('mcp/agile-workflow.md'));
    }
}
