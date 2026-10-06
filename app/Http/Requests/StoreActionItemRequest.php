<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()?->is_active) {
            return false;
        }

        return $this->user()->role === 'director'
            || $this->integer('department_id') === $this->user()->department_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $departmentId = $this->integer('department_id');

        return [
            'department_id' => ['required', Rule::exists('departments', 'id')],
            'kpi_definition_id' => ['nullable', Rule::exists('kpi_definitions', 'id')->where('department_id', $departmentId)],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'owner_name' => ['required', 'string', 'max:100'],
            'due_date' => ['required', Rule::date()->format('Y-m-d')],
        ];
    }

    public function attributes(): array
    {
        return [
            'department_id' => 'divisi',
            'kpi_definition_id' => 'indikator KPI',
            'owner_name' => 'penanggung jawab',
            'due_date' => 'tenggat',
        ];
    }
}
