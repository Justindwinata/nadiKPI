<?php

namespace App\Http\Requests;

use App\Models\CertificationBatch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCertificationBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->canAccess('certification');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['planned', 'document_review', 'assessment', 'decision', 'completed'])],
            'passed' => ['sometimes', 'integer', 'min:0'],
            'failed' => ['sometimes', 'integer', 'min:0'],
            'assessment_completed_at' => ['nullable', 'date'],
            'decision_at' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $batch = $this->route('batch');
            if (! $batch instanceof CertificationBatch) {
                return;
            }

            $passed = $this->has('passed') ? $this->integer('passed') : $batch->passed;
            $failed = $this->has('failed') ? $this->integer('failed') : $batch->failed;
            $status = (string) $this->input('status');
            $stages = ['planned', 'document_review', 'assessment', 'decision', 'completed'];
            $currentStage = array_search($batch->status, $stages, true);
            $requestedStage = array_search($status, $stages, true);

            if ($currentStage !== false && $requestedStage !== false && $requestedStage < $currentStage) {
                $validator->errors()->add('status', 'Tahap sertifikasi tidak dapat dikembalikan ke tahap sebelumnya. Gunakan koreksi data terkontrol bila diperlukan.');
            }
            if ($currentStage !== false && $requestedStage !== false && $requestedStage > ($currentStage + 1)) {
                $validator->errors()->add('status', 'Tahap sertifikasi harus diselesaikan berurutan tanpa melewati tahap proses.');
            }

            if (($passed + $failed) > $batch->total_assesi) {
                $validator->errors()->add('passed', 'Jumlah kompeten dan belum kompeten tidak boleh melebihi jumlah asesi.');
            }

            $issued = (int) $batch->issuances()->sum('issued_count');
            if ($passed < $issued) {
                $validator->errors()->add('passed', 'Jumlah kompeten tidak boleh lebih kecil dari sertifikat yang sudah diterbitkan.');
            }

            if (in_array($status, ['decision', 'completed'], true) && ($passed + $failed) !== $batch->total_assesi) {
                $validator->errors()->add('passed', 'Tahap keputusan/selesai membutuhkan hasil final untuk seluruh asesi.');
            }

            $assessmentCompleted = $this->input('assessment_completed_at') ?: $batch->assessment_completed_at?->toIso8601String();
            $decisionAt = $this->input('decision_at') ?: $batch->decision_at?->toIso8601String();

            if (in_array($status, ['decision', 'completed'], true) && ! $assessmentCompleted) {
                $validator->errors()->add('assessment_completed_at', 'Waktu selesai asesmen wajib dicatat sebelum keputusan.');
            }

            if (in_array($status, ['decision', 'completed'], true) && ! $decisionAt) {
                $validator->errors()->add('decision_at', 'Waktu keputusan wajib dicatat.');
            }

            if ($assessmentCompleted && strtotime($assessmentCompleted) < $batch->assessment_date->startOfDay()->timestamp) {
                $validator->errors()->add('assessment_completed_at', 'Waktu selesai asesmen tidak boleh lebih awal dari tanggal asesmen.');
            }
            if ($decisionAt && strtotime($decisionAt) < $batch->assessment_date->startOfDay()->timestamp) {
                $validator->errors()->add('decision_at', 'Waktu keputusan tidak boleh lebih awal dari tanggal asesmen.');
            }
            if ($assessmentCompleted && $decisionAt && strtotime($decisionAt) < strtotime($assessmentCompleted)) {
                $validator->errors()->add('decision_at', 'Waktu keputusan tidak boleh lebih awal dari selesainya asesmen.');
            }

            if ($status === 'completed') {
                if ($issued < $passed) {
                    $validator->errors()->add('status', 'Batch hanya dapat diselesaikan setelah seluruh sertifikat peserta kompeten diterbitkan.');
                }
            }
        }];
    }
}
