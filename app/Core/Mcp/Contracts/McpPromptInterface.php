<?php

declare(strict_types=1);

namespace App\Core\Mcp\Contracts;

interface McpPromptInterface
{
    public function name(): string;

    public function description(): string;

    /** @return array<int, array{role: string, content: array{type: string, text: string}}> */
    public function messages(): array;
}
