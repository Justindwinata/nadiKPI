<?php

namespace App\Http\Requests;

use App\Models\CertificationBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificateIssuanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('batch');

        return $this->user()?->is_active
            && $batch instanceof CertificationBatch
            && $this->user()->canAccess('certification');
    }

    public function rules(): array
    {
        return [
            'issued_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'issued_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100', Rule::unique('certificate_issuances', 'reference')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'issued_count' => 'jumlah sertifikat',
            'issued_at' => 'waktu penerbitan',
            'reference' => 'referensi penerbitan',
        ];
    }
}
