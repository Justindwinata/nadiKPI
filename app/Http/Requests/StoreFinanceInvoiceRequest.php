<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinanceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->canAccess('finance');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:100', 'unique:finance_invoices,invoice_number'],
            'issued_on' => ['required', Rule::date()->format('Y-m-d')],
            'due_on' => ['required', Rule::date()->format('Y-m-d'), 'after_or_equal:issued_on'],
            'customer_name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:100'],
            'department_code' => ['nullable', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
        ];
    }
}
