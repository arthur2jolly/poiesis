<?php

namespace App\Core\Http\Resources;

use App\Core\Models\Epic;
use App\Core\Models\Story;
use App\Core\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtifactSearchResource extends JsonResource
{
    /** @var array<class-string, string> */
    private const TYPE_MAP = [
        Epic::class => 'epic',
        Story::class => 'story',
        Task::class => 'task',
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
