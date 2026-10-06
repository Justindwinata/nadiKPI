<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active
            && $this->user()->canManageMasterData((string) $this->route('type'));
    }

    public function rules(): array
    {
        $id = (int) $this->route('id');

        return match ((string) $this->route('type')) {
            'certification-schemes' => [
                'name' => ['required', 'string', 'max:160'],
                'category' => ['required', 'string', 'max:100'],
                'units_count' => ['required', 'integer', 'min:1', 'max:200'],
                'is_active' => ['required', 'boolean'],
                'valid_until' => ['nullable', Rule::date()->format('Y-m-d')],
                'evidence_reference' => ['nullable', 'string', 'max:255'],
                'change_reason' => ['required', 'string', 'max:1000'],
            ],
            'tuks' => [
                'name' => ['required', 'string', 'max:160'],
                'city' => ['required', 'string', 'max:100'],
                'status' => ['required', Rule::in(['active', 'inactive', 'maintenance'])],
                'monthly_capacity' => ['required', 'integer', 'min:1', 'max:10000'],
                'verification_valid_until' => ['nullable', Rule::date()->format('Y-m-d')],
                'evidence_reference' => ['nullable', 'string', 'max:255'],
                'change_reason' => ['required', 'string', 'max:1000'],
            ],
            'assessors' => [
                'name' => ['required', 'string', 'max:160'],
                'specialization' => ['required', 'string', 'max:160'],
                'status' => ['required', Rule::in(['active', 'expiring', 'inactive'])],
                'valid_until' => ['required', Rule::date()->format('Y-m-d')],
                'change_reason' => ['required', 'string', 'max:1000'],
            ],
            'it-services' => [
                'owner' => ['required', 'string', 'max:160'],
                'target_uptime' => ['required', 'numeric', 'between:90,100'],
                'monitoring_started_at' => ['nullable', 'date'],
                'status' => ['required', Rule::in(['operational', 'degraded', 'maintenance'])],
                'change_reason' => ['required', 'string', 'max:1000'],
            ],
            default => [],
        };
    }
}
