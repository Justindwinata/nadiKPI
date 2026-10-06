<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveItIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->canAccess('it');
    }

    public function rules(): array
    {
        return [
            'resolved_at' => ['nullable', 'date'],
            'resolution_summary' => ['required', 'string', 'max:2000'],
            'root_cause' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
