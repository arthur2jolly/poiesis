<?php

namespace App\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'titre' => $this->titre,
            'description' => $this->description,
            'modules' => $this->modules,
            'created_at' => $this->created_at,
        ];
    }
}
