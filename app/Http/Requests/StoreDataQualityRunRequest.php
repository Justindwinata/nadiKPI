<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDataQualityRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->canAccess('it');
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100', 'unique:data_quality_runs,reference'],
            'dataset_name' => ['required', 'string', 'max:160'],
            'source_system' => ['required', 'string', 'max:160'],
            'assessed_at' => ['required', 'date'],
            'total_records' => ['required', 'integer', 'min:1'],
            'valid_records' => ['required', 'integer', 'min:0'],
            'missing_required_records' => ['nullable', 'integer', 'min:0'],
            'duplicate_records' => ['nullable', 'integer', 'min:0'],
            'freshness_failures' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $total = (int) $this->input('total_records', 0);
                $valid = (int) $this->input('valid_records', 0);

                if ($valid > $total) {
                    $validator->errors()->add('valid_records', 'Jumlah rekam valid tidak boleh melebihi total rekam.');
                }
            },
        ];
    }
}
