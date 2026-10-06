<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class StoreItIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->canAccess('it');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'it_service_id' => ['required', Rule::exists('it_services', 'id')->where(fn (Builder $q) => $q->whereNull('archived_at'))],
            'reference' => ['required', 'string', 'max:100', 'unique:it_incidents,reference'],
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'started_at' => ['required', Rule::date()->format('Y-m-d\TH:i')],
            'summary' => ['required', 'string', 'max:1000'],
        ];
    }
}
