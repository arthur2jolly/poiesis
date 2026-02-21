<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEpicRequest extends FormRequest
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
        ];
    }
}
