<?php

namespace App\Http\Requests;

use App\Models\KpiDefinition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKpiMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $kpi = $this->route('kpi');

        return $this->user()?->is_active
            && $kpi instanceof KpiDefinition
            && ($this->user()->role === 'director' || $this->user()->department_id === $kpi->department_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', Rule::date()->format('Y-m-d')],
            'actual' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
