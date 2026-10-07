<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificationBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->canAccess('certification');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60', 'unique:certification_batches,code'],
            'certification_scheme_id' => ['required', Rule::exists('certification_schemes', 'id')->where(fn (Builder $q) => $q->whereNull('archived_at')->where('is_active', true))],
            'tuk_id' => ['required', Rule::exists('tuks', 'id')->where(fn (Builder $q) => $q->whereNull('archived_at')->where('status', 'active'))],
            'assessor_id' => ['required', Rule::exists('assessors', 'id')->where(fn (Builder $q) => $q->whereNull('archived_at')->whereIn('status', ['active', 'expiring']))],
            'assessment_date' => ['required', Rule::date()->format('Y-m-d')],
            'total_assesi' => ['required', 'integer', 'min:1', 'max:10000'],
            'revenue' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['planned', 'document_review', 'assessment'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'certification_scheme_id' => 'skema sertifikasi',
            'tuk_id' => 'TUK',
            'assessor_id' => 'asesor',
            'assessment_date' => 'tanggal asesmen',
            'total_assesi' => 'jumlah asesi',
        ];
    }
}
