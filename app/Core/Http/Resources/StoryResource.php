<?php

namespace App\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'identifier' => $this->identifier,
            'titre' => $this->titre,
            'description' => $this->description,
            'type' => $this->type,
            'nature' => $this->nature,
            'statut' => $this->statut,
            'priorite' => $this->priorite,
            'ordre' => $this->ordre,
            'story_points' => $this->story_points,
            'reference_doc' => $this->reference_doc,
            'tags' => $this->tags,
            'epic' => $this->epic?->identifier,
            'tasks_count' => $this->whenCounted('tasks'),
            'blocked_by' => $this->blockedBy()->map(fn ($item) => $item->identifier)->values(),
            'blocks' => $this->blocks()->map(fn ($item) => $item->identifier)->values(),
            'created_at' => $this->created_at,
        ];
    }
}
