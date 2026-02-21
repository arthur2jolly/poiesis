<?php

namespace App\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtifactSearchResource extends JsonResource
{
    /** @var array<class-string, string> */
    private const TYPE_MAP = [
        \App\Core\Models\Epic::class => 'epic',
        \App\Core\Models\Story::class => 'story',
        \App\Core\Models\Task::class => 'task',
    ];

    public function toArray(Request $request): array
    {
        return [
            'identifier' => $this->identifier,
            'type' => self::TYPE_MAP[$this->resource::class] ?? $this->resource::class,
            'titre' => $this->titre,
        ];
    }
}
