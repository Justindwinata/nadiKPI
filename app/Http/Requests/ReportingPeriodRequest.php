<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $year = $this->integer('year') ?: now()->year;
                $month = $this->integer('month') ?: now()->month;
                $requested = CarbonImmutable::create($year, $month, 1)->startOfMonth();
                if ($requested->gt(now()->startOfMonth())) {
                    $validator->errors()->add('month', 'Periode laporan tidak boleh berada di masa depan.');
                }
            },
        ];
    }

    public function period(): CarbonImmutable
    {
        $year = $this->integer('year') ?: now()->year;
        $month = $this->integer('month') ?: now()->month;

        return CarbonImmutable::create($year, $month)->endOfMonth();
    }
}
