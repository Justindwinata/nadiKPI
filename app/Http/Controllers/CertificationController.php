<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportingPeriodRequest;
use App\Http\Requests\StoreCertificateIssuanceRequest;
use App\Http\Requests\StoreCertificationBatchRequest;
use App\Http\Requests\UpdateCertificationBatchRequest;
use App\Models\Assessor;
use App\Models\AuditLog;
use App\Models\CertificateIssuance;
use App\Models\CertificationBatch;
use App\Models\CertificationScheme;
use App\Models\Tuk;
use App\Services\KpiAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CertificationController extends Controller
{
    public function index(ReportingPeriodRequest $request, KpiAnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->certification($request->period()) + [
            'catalogs' => [
                'schemes' => CertificationScheme::query()->whereNull('archived_at')->where('is_active', true)->select(['id', 'code', 'name'])->orderBy('name')->get(),
                'tuks' => Tuk::query()->whereNull('archived_at')->where('status', 'active')->select(['id', 'code', 'name', 'city'])->orderBy('name')->get(),
                'assessors' => Assessor::query()->whereNull('archived_at')->whereIn('status', ['active', 'expiring'])->select(['id', 'registration_no', 'name', 'status'])->orderBy('name')->get(),
            ],
        ]);
    }

    public function storeBatch(StoreCertificationBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $batch = DB::transaction(function () use ($data, $request): CertificationBatch {
            $scheme = CertificationScheme::query()->whereKey($data['certification_scheme_id'])->lockForUpdate()->firstOrFail();
            $tuk = Tuk::query()->whereKey($data['tuk_id'])->lockForUpdate()->firstOrFail();
            $assessor = Assessor::query()->whereKey($data['assessor_id'])->lockForUpdate()->firstOrFail();

            if ($scheme->archived_at || ! $scheme->is_active) {
                throw ValidationException::withMessages(['certification_scheme_id' => 'Skema sudah tidak aktif atau telah diarsipkan. Muat ulang master data.']);
            }
            if ($tuk->archived_at || $tuk->status !== 'active') {
                throw ValidationException::withMessages(['tuk_id' => 'TUK sudah tidak aktif atau telah diarsipkan. Muat ulang master data.']);
            }
            if ($assessor->archived_at || ! in_array($assessor->status, ['active', 'expiring'], true)) {
                throw ValidationException::withMessages(['assessor_id' => 'Asesor sudah tidak tersedia untuk batch aktif. Muat ulang master data.']);
            }

            $batch = CertificationBatch::create($data + [
                'passed' => 0,
                'failed' => 0,
                'pending' => $data['total_assesi'],
                'certificates_issued' => 0,
                'issued_on_time' => 0,
            ]);
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'create',
                'entity_type' => CertificationBatch::class,
                'entity_id' => $batch->id,
                'changes' => $data,
                'ip_address' => $request->ip(),
            ]);

            return $batch;
        });

        return response()->json(['batch' => $batch->load(['scheme', 'tuk', 'assessor', 'issuances'])], 201);
    }

    public function updateBatch(UpdateCertificationBatchRequest $request, CertificationBatch $batch): JsonResponse
    {
        $data = $request->validated();

        $updated = DB::transaction(function () use ($batch, $data, $request): CertificationBatch {
            /** @var CertificationBatch $locked */
            $locked = CertificationBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $before = $locked->only([
                'status', 'passed', 'failed', 'pending', 'assessment_completed_at', 'decision_at',
                'certificate_due_at', 'completed_at', 'certificates_issued', 'issued_on_time',
            ]);

            // Re-check lifecycle and issuance invariants after acquiring the row lock.
            // FormRequest validation happens before this transaction and can otherwise race
            // with a concurrent decision/issuance request.
            $stages = ['planned', 'document_review', 'assessment', 'decision', 'completed'];
            $currentStage = array_search($locked->status, $stages, true);
            $requestedStage = array_search($data['status'], $stages, true);
            if ($currentStage !== false && $requestedStage !== false && $requestedStage < $currentStage) {
                throw ValidationException::withMessages(['status' => 'Tahap sertifikasi tidak dapat dikembalikan ke tahap sebelumnya.']);
            }
            if ($currentStage !== false && $requestedStage !== false && $requestedStage > ($currentStage + 1)) {
                throw ValidationException::withMessages(['status' => 'Tahap sertifikasi harus diselesaikan berurutan tanpa melewati tahap proses.']);
            }

            $effectivePassed = array_key_exists('passed', $data) ? (int) $data['passed'] : (int) $locked->passed;
            $effectiveFailed = array_key_exists('failed', $data) ? (int) $data['failed'] : (int) $locked->failed;
            if (($effectivePassed + $effectiveFailed) > (int) $locked->total_assesi) {
                throw ValidationException::withMessages(['passed' => 'Jumlah kompeten dan belum kompeten tidak boleh melebihi jumlah asesi.']);
            }
            if (in_array($data['status'], ['decision', 'completed'], true)
                && ($effectivePassed + $effectiveFailed) !== (int) $locked->total_assesi) {
                throw ValidationException::withMessages(['passed' => 'Tahap keputusan/selesai membutuhkan hasil final untuk seluruh asesi.']);
            }

            $effectiveAssessmentCompleted = array_key_exists('assessment_completed_at', $data)
                ? ($data['assessment_completed_at'] ? CarbonImmutable::parse($data['assessment_completed_at']) : null)
                : ($locked->assessment_completed_at ? CarbonImmutable::parse($locked->assessment_completed_at) : null);
            $effectiveDecisionAt = array_key_exists('decision_at', $data)
                ? ($data['decision_at'] ? CarbonImmutable::parse($data['decision_at']) : null)
                : ($locked->decision_at ? CarbonImmutable::parse($locked->decision_at) : null);

            if (in_array($data['status'], ['decision', 'completed'], true) && ! $effectiveAssessmentCompleted) {
                throw ValidationException::withMessages(['assessment_completed_at' => 'Waktu selesai asesmen wajib dicatat sebelum keputusan.']);
            }
            if (in_array($data['status'], ['decision', 'completed'], true) && ! $effectiveDecisionAt) {
                throw ValidationException::withMessages(['decision_at' => 'Waktu keputusan wajib dicatat.']);
            }
            $assessmentStart = CarbonImmutable::parse($locked->assessment_date)->startOfDay();
            if ($effectiveAssessmentCompleted && $effectiveAssessmentCompleted->lt($assessmentStart)) {
                throw ValidationException::withMessages(['assessment_completed_at' => 'Waktu selesai asesmen tidak boleh lebih awal dari tanggal asesmen.']);
            }
            if ($effectiveDecisionAt && $effectiveDecisionAt->lt($assessmentStart)) {
                throw ValidationException::withMessages(['decision_at' => 'Waktu keputusan tidak boleh lebih awal dari tanggal asesmen.']);
            }
            if ($effectiveAssessmentCompleted && $effectiveDecisionAt && $effectiveDecisionAt->lt($effectiveAssessmentCompleted)) {
                throw ValidationException::withMessages(['decision_at' => 'Waktu keputusan tidak boleh lebih awal dari selesainya asesmen.']);
            }

            $issued = (int) $locked->issuances()->sum('issued_count');
            $earliestIssuance = $locked->issuances()->min('issued_at');
            if ($earliestIssuance && $effectiveDecisionAt && $effectiveDecisionAt->gt(CarbonImmutable::parse($earliestIssuance))) {
                throw ValidationException::withMessages(['decision_at' => 'Waktu keputusan tidak dapat dipindahkan setelah waktu sertifikat yang sudah diterbitkan.']);
            }
            if ($effectivePassed < $issued) {
                throw ValidationException::withMessages(['passed' => 'Jumlah kompeten tidak boleh lebih kecil dari sertifikat yang sudah diterbitkan.']);
            }
            if ($data['status'] === 'completed' && $issued < $effectivePassed) {
                throw ValidationException::withMessages(['status' => 'Batch hanya dapat diselesaikan setelah seluruh sertifikat peserta kompeten diterbitkan.']);
            }

            if (array_key_exists('passed', $data) || array_key_exists('failed', $data)) {
                $passed = array_key_exists('passed', $data) ? (int) $data['passed'] : $locked->passed;
                $failed = array_key_exists('failed', $data) ? (int) $data['failed'] : $locked->failed;
                $data['passed'] = $passed;
                $data['failed'] = $failed;
                $data['pending'] = max(0, $locked->total_assesi - $passed - $failed);
            }

            if (! empty($data['decision_at'])) {
                $decisionAt = CarbonImmutable::parse($data['decision_at']);
                $data['certificate_due_at'] = $decisionAt->addDays(30);
            } elseif (in_array($data['status'], ['decision', 'completed'], true) && $locked->decision_at && ! $locked->certificate_due_at) {
                $data['certificate_due_at'] = CarbonImmutable::instance($locked->decision_at)->addDays(30);
            }

            if ($data['status'] === 'completed' && ! $locked->completed_at) {
                $latestIssuance = $locked->issuances()->max('issued_at');
                $completionAt = collect([now(), $locked->decision_at, $latestIssuance])
                    ->filter()
                    ->map(fn ($value) => CarbonImmutable::parse($value))
                    ->sortByDesc(fn (CarbonImmutable $value) => $value->timestamp)
                    ->first();
                $data['completed_at'] = $completionAt;
            } elseif ($data['status'] !== 'completed' && $locked->completed_at) {
                $data['completed_at'] = null;
            }

            $locked->update($data);

            if ($locked->issuances()->exists()) {
                $issuedTotal = (int) $locked->issuances()->sum('issued_count');
                $issuedOnTime = $locked->certificate_due_at
                    ? (int) $locked->issuances()->where('issued_at', '<=', $locked->certificate_due_at)->sum('issued_count')
                    : 0;
                $locked->update([
                    'certificates_issued' => $issuedTotal,
                    'issued_on_time' => min($issuedOnTime, $locked->passed),
                ]);
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'update',
                'entity_type' => CertificationBatch::class,
                'entity_id' => $locked->id,
                'changes' => ['before' => $before, 'after' => $locked->fresh()->only(array_keys($before))],
                'ip_address' => $request->ip(),
            ]);

            return $locked->fresh(['scheme', 'tuk', 'assessor', 'issuances']);
        });

        return response()->json(['batch' => $updated]);
    }

    public function storeIssuance(StoreCertificateIssuanceRequest $request, CertificationBatch $batch): JsonResponse
    {
        $data = $request->validated();

        $result = DB::transaction(function () use ($request, $batch, $data): array {
            /** @var CertificationBatch $locked */
            $locked = CertificationBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if (! $locked->decision_at || ! $locked->certificate_due_at || $locked->passed <= 0) {
                throw ValidationException::withMessages([
                    'issued_count' => 'Penerbitan sertifikat hanya dapat dicatat setelah keputusan kompetensi final tersedia.',
                ]);
            }

            $alreadyIssued = (int) $locked->issuances()->sum('issued_count');
            if (($alreadyIssued + (int) $data['issued_count']) > $locked->passed) {
                throw ValidationException::withMessages([
                    'issued_count' => 'Jumlah sertifikat yang diterbitkan tidak boleh melebihi jumlah peserta kompeten yang belum diterbitkan sertifikatnya.',
                ]);
            }

            $issuedAt = CarbonImmutable::parse($data['issued_at']);
            if ($issuedAt->lt($locked->decision_at)) {
                throw ValidationException::withMessages([
                    'issued_at' => 'Waktu penerbitan tidak boleh lebih awal dari waktu keputusan.',
                ]);
            }

            $issuance = CertificateIssuance::create([
                'certification_batch_id' => $locked->id,
                'issued_count' => (int) $data['issued_count'],
                'issued_at' => $issuedAt,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            $issuedTotal = (int) $locked->issuances()->sum('issued_count');
            $issuedOnTime = (int) $locked->issuances()
                ->where('issued_at', '<=', $locked->certificate_due_at)
                ->sum('issued_count');

            $locked->update([
                'certificates_issued' => $issuedTotal,
                'issued_on_time' => min($issuedOnTime, $locked->passed),
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'certificate_issuance',
                'entity_type' => CertificateIssuance::class,
                'entity_id' => $issuance->id,
                'changes' => [
                    'batch_id' => $locked->id,
                    'issued_count' => $issuance->issued_count,
                    'issued_at' => $issuance->issued_at->toIso8601String(),
                    'reference' => $issuance->reference,
                    'certificate_due_at' => $locked->certificate_due_at->toIso8601String(),
                ],
                'ip_address' => $request->ip(),
            ]);

            return [
                'issuance' => $issuance,
                'batch' => $locked->fresh(['scheme', 'tuk', 'assessor', 'issuances']),
            ];
        });

        return response()->json($result, 201);
    }
}
