<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->canAccess('finance');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'paid_on' => ['required', Rule::date()->format('Y-m-d')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['required', 'string', 'max:100', 'unique:finance_payments,reference'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
