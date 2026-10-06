<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReverseFinancialRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->canAccess('finance');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'recorded_on' => ['required', Rule::date()->format('Y-m-d')],
            'reason' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100', 'unique:financial_records,reference'],
        ];
    }
}
