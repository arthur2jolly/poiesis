<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titre' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(config('core.item_types'))],
            'description' => ['nullable', 'string'],
            'nature' => ['nullable', Rule::in(config('core.work_natures'))],
            'priorite' => ['nullable', Rule::in(config('core.priorities'))],
            'ordre' => ['nullable', 'integer', 'min:0'],
            'story_points' => ['nullable', 'integer', 'min:0'],
            'reference_doc' => ['nullable', 'url', 'max:2048'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ];
    }
}
