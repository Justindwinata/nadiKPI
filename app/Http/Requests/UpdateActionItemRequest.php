<?php

namespace App\Http\Requests;

use App\Models\ActionItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->route('action');

        return $this->user()?->is_active
            && $action instanceof ActionItem
            && ($this->user()->role === 'director'
                || $this->user()->department?->code === 'quality'
                || $this->user()->department_id === $action->department_id);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['open', 'in_progress', 'blocked', 'completed'])],
            'resolution_note' => ['nullable', 'string', 'max:2000', Rule::requiredIf($this->input('status') === 'completed')],
            'resolution_evidence' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->input('status') === 'completed')],
        ];
    }
}
