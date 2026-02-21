<?php

namespace App\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'estimation_temps' => $this->estimation_temps,
            'tags' => $this->tags,
            'story' => $this->story ? $this->story->identifier : null,
            'project' => $this->project->code,
            'blocked_by' => $this->blockedBy()->map(fn ($item) => $item->identifier)->values(),
            'blocks' => $this->blocks()->map(fn ($item) => $item->identifier)->values(),
            'created_at' => $this->created_at,
        ];
    }
}
