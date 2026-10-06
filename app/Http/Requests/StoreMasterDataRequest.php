<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active
            && $this->user()->canManageMasterData((string) $this->route('type'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return match ((string) $this->route('type')) {
            'certification-schemes' => [
                'code' => ['required', 'string', 'max:40', Rule::unique('certification_schemes', 'code')],
                'name' => ['required', 'string', 'max:160'],
                'category' => ['required', 'string', 'max:100'],
                'units_count' => ['required', 'integer', 'min:1', 'max:200'],
                'is_active' => ['required', 'boolean'],
                'valid_until' => ['nullable', Rule::date()->format('Y-m-d')],
                'evidence_reference' => ['nullable', 'string', 'max:255'],
            ],
            'tuks' => [
                'code' => ['required', 'string', 'max:40', Rule::unique('tuks', 'code')],
                'name' => ['required', 'string', 'max:160'],
                'city' => ['required', 'string', 'max:100'],
                'status' => ['required', Rule::in(['active', 'inactive', 'maintenance'])],
                'monthly_capacity' => ['required', 'integer', 'min:1', 'max:10000'],
                'verification_valid_until' => ['nullable', Rule::date()->format('Y-m-d')],
                'evidence_reference' => ['nullable', 'string', 'max:255'],
            ],
            'assessors' => [
                'registration_no' => ['required', 'string', 'max:60', Rule::unique('assessors', 'registration_no')],
                'name' => ['required', 'string', 'max:160'],
                'specialization' => ['required', 'string', 'max:160'],
                'status' => ['required', Rule::in(['active', 'expiring', 'inactive'])],
                'valid_until' => ['required', Rule::date()->format('Y-m-d')],
            ],
            'it-services' => [
                'name' => ['required', 'string', 'max:160', Rule::unique('it_services', 'name')],
                'owner' => ['required', 'string', 'max:160'],
                'target_uptime' => ['required', 'numeric', 'between:90,100'],
                'monitoring_started_at' => ['nullable', 'date'],
                'status' => ['required', Rule::in(['operational', 'degraded', 'maintenance'])],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode',
            'name' => 'nama',
            'category' => 'kategori',
            'units_count' => 'jumlah unit kompetensi',
            'is_active' => 'status aktif',
            'city' => 'kota',
            'status' => 'status',
            'monthly_capacity' => 'kapasitas bulanan',
            'registration_no' => 'nomor registrasi',
            'specialization' => 'spesialisasi',
            'valid_until' => 'masa berlaku',
            'verification_valid_until' => 'masa berlaku verifikasi',
            'evidence_reference' => 'referensi bukti',
            'owner' => 'penanggung jawab',
            'target_uptime' => 'target ketersediaan',
            'monitoring_started_at' => 'mulai pemantauan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'unique' => ':attribute sudah digunakan.',
            'in' => ':attribute yang dipilih tidak valid.',
            'integer' => ':attribute harus berupa bilangan bulat.',
            'numeric' => ':attribute harus berupa angka.',
            'between' => ':attribute harus berada di antara :min dan :max.',
            'min' => ':attribute minimal :min.',
            'max' => ':attribute maksimal :max.',
            'date_format' => ':attribute harus menggunakan format tanggal yang benar.',
        ];
    }
}
