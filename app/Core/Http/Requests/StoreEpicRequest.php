<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEpicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titre' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
