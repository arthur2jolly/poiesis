<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titre' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'nature' => ['nullable', Rule::in(config('core.work_natures'))],
            'priorite' => ['nullable', Rule::in(config('core.priorities'))],
            'ordre' => ['nullable', 'integer', 'min:0'],
            'estimation_temps' => ['nullable', 'integer', 'min:0'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ];
    }
}
