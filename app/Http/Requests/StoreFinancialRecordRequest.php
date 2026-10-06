<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->canAccess('finance');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recorded_on' => ['required', Rule::date()->format('Y-m-d')],
            'type' => ['required', Rule::in(['revenue', 'expense', 'budget'])],
            'category' => ['required', 'string', 'max:100'],
            'department_code' => ['nullable', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100', 'unique:financial_records,reference'],
        ];
    }
}
