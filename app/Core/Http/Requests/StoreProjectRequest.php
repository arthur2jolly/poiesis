<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'regex:/^[A-Za-z0-9\-]{2,25}$/', 'unique:projects,code'],
            'titre' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
