<?php

namespace App\Services;

use App\Models\Assessor;
use App\Models\AuditLog;
use App\Models\CertificateIssuance;
use App\Models\CertificationBatch;
use App\Models\CertificationScheme;
use App\Models\DataImportBatch;
use App\Models\DataQualityRun;
use App\Models\DataSource;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancialRecord;
use App\Models\IntegrationRecordLink;
use App\Models\IntegrationStagingRow;
use App\Models\ItIncident;
use App\Models\ItService;
use App\Models\Tuk;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationOnboardingService
{
    public function catalog(): array
    {
        return collect($this->definitions())->map(function (array $definition, string $type) {
            return [
                'type' => $type,
                'label' => $definition['label'],
                'domain' => $definition['domain'],
                'description' => $definition['description'],
                'mutable' => $definition['mutable'],
                'key_field' => $definition['key_field'],
                'fields' => collect($definition['fields'])->map(fn (array $field, string $name) => [
                    'name' => $name,
                    'label' => $field['label'],
                    'required' => $field['required'] ?? false,
                    'sample' => $field['sample'] ?? null,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    public function definition(string $datasetType): array
    {
        $definition = $this->definitions()[$datasetType] ?? null;
        if (! $definition) {
            throw ValidationException::withMessages(['dataset_type' => 'Jenis dataset integrasi tidak didukung.']);
        }

        return $definition;
    }

    public function canView(User $user, string $datasetType): bool
    {
        $definition = $this->definition($datasetType);
        if ($user->hasPermission('governance.provenance.manage')) {
            return true;
        }

        return match ($definition['domain']) {
            'certification' => $user->hasPermission('certification.view'),
            'finance' => $user->hasPermission('finance.view'),
            'it' => $user->hasPermission('it.view'),
            default => false,
        };
    }

    public function canManage(User $user, string $datasetType): bool
    {
        $definition = $this->definition($datasetType);
        if ($user->hasPermission('governance.provenance.manage')) {
            return true;
        }

        return match ($definition['domain']) {
            'certification' => $user->hasPermission('certification.manage') || $user->hasPermission('master_data.certification.manage'),
            'finance' => $user->hasPermission('finance.ledger.manage') || $user->hasPermission('finance.invoices.manage') || $user->hasPermission('finance.payments.manage'),
            'it' => $user->hasPermission('it.incidents.manage') || $user->hasPermission('it.data_quality.manage') || $user->hasPermission('master_data.it.manage'),
            default => false,
        };
    }

    public function templateRows(string $datasetType): array
    {
        $definition = $this->definition($datasetType);
        $headers = array_keys($definition['fields']);
        $sample = [];
        foreach ($definition['fields'] as $field) {
            $sample[] = $field['sample'] ?? '';
        }

        return [$headers, $sample];
    }

    public function stage(
        UploadedFile $file,
        DataSource $source,
        string $datasetType,
        User $user,
        ?array $columnMapping = null,
        ?array $defaults = null,
        ?string $datasetName = null,
    ): DataImportBatch {
        $definition = $this->definition($datasetType);
        $path = $file->getRealPath();
        $sha256 = hash_file('sha256', $path);

        [$headers, $rows] = $this->readCsv($path);
        $mapping = $this->resolveMapping($definition, $headers, $columnMapping ?? []);
        $reference = 'INT-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(6));

        $batch = DB::transaction(function () use ($source, $datasetType, $datasetName, $file, $sha256, $headers, $mapping, $defaults, $user, $rows, $reference): DataImportBatch {
            $actor = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->hasPermission('integrations.manage') && $this->canManage($actor, $datasetType), 403, 'Hak onboarding dataset sudah berubah. Muat ulang sesi dan coba lagi.');

            // Serialize staging for one source so two identical uploads cannot both
            // pass the idempotency check before either batch becomes visible.
            $lockedSource = DataSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if (! $lockedSource->is_active) {
                throw ValidationException::withMessages(['data_source_id' => 'Sumber data tidak aktif.']);
            }

            $existing = DataImportBatch::query()
                ->where('data_source_id', $lockedSource->id)
                ->where('dataset_type', $datasetType)
                ->where('sha256', $sha256)
                ->whereIn('status', ['staged', 'staged_with_errors', 'validated', 'published', 'published_with_errors'])
                ->latest('id')
                ->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'file' => "File identik sudah pernah diproses sebagai {$existing->reference}. Gunakan batch tersebut agar impor tetap idempotent.",
                ]);
            }

            $batch = DataImportBatch::create([
                'reference' => $reference,
                'data_source_id' => $lockedSource->id,
                'dataset_name' => $datasetName ?: $this->definition($datasetType)['label'],
                'dataset_type' => $datasetType,
                'file_name' => $file->getClientOriginalName(),
                'sha256' => $sha256,
                'column_mapping' => $mapping,
                'mapping_defaults' => $defaults ?? [],
                'source_headers' => $headers,
                'imported_at' => now(),
                'staged_at' => now(),
                'status' => 'staged',
                'reconciliation_status' => 'pending',
                'created_by' => $actor->id,
            ]);

            foreach ($rows as $index => $raw) {
                IntegrationStagingRow::create([
                    'data_import_batch_id' => $batch->id,
                    'row_number' => $index + 2,
                    'raw_payload' => $raw,
                    'status' => 'pending',
                ]);
            }

            $this->revalidate($batch, $mapping, $defaults ?? []);
            $this->audit($actor, 'stage_integration', $batch, [
                'reference' => $batch->reference,
                'dataset_type' => $datasetType,
                'source' => $lockedSource->code,
                'sha256' => $sha256,
                'rows' => count($rows),
            ]);

            return $batch->fresh(['source', 'stagingRows']);
        });

        return $batch;
    }

    public function revalidate(DataImportBatch $batch, array $mapping, array $defaults = []): DataImportBatch
    {
        $definition = $this->definition((string) $batch->dataset_type);
        $headers = $batch->source_headers ?? [];
        $this->validateMapping($definition, $headers, $mapping, $defaults);

        $seen = [];
        $valid = 0;
        $invalid = 0;
        $duplicates = 0;
        $planned = ['insert' => 0, 'update' => 0, 'skip' => 0];

        foreach ($batch->stagingRows()->orderBy('row_number')->get() as $row) {
            $normalized = $this->normalizeRow($definition, $row->raw_payload ?? [], $mapping, $defaults);
            [$errors, $externalKey] = $this->validateRow($definition, $normalized);
            $rowHash = $externalKey ? $this->rowHash($normalized) : null;
            $action = null;

            if ($externalKey && isset($seen[$externalKey])) {
                $errors[] = "External key '{$externalKey}' duplikat dalam file yang sama.";
            }
            if ($externalKey) {
                $seen[$externalKey] = true;
            }

            if (! $errors && $externalKey) {
                $link = IntegrationRecordLink::query()
                    ->where('data_source_id', $batch->data_source_id)
                    ->where('dataset_type', $batch->dataset_type)
                    ->where('external_key', $externalKey)
                    ->first();

                if ($link && ! $this->linkEntityExists($link)) {
                    $errors[] = 'Provenance link mengarah ke entity yang sudah tidak tersedia. Rekonsiliasi link diperlukan sebelum batch dapat dipublish.';
                } elseif (! $link && ! $definition['mutable'] && $this->immutableEntityExists($definition['type'], $normalized)) {
                    $errors[] = 'Record immutable dengan business key yang sama sudah ada di NADI tetapi belum terhubung ke source ini. Lakukan rekonsiliasi manual, jangan membuat duplikat.';
                } elseif (! $link) {
                    $action = 'insert';
                } elseif (hash_equals($link->row_hash, $rowHash)) {
                    $action = 'skip';
                    $duplicates++;
                } elseif ($definition['mutable']) {
                    $action = 'update';
                } else {
                    $errors[] = 'Record dengan external key ini sudah pernah dipublikasikan dan isinya berubah. Dataset immutable harus dikoreksi melalui workflow domain, bukan overwrite impor.';
                }

                if (! $errors && in_array($action, ['insert', 'update'], true)) {
                    $authorityError = $this->authorityConflict($batch, $definition['type'], $normalized, $link);
                    if ($authorityError) {
                        $errors[] = $authorityError;
                        $action = null;
                    }
                }
            }

            if ($errors) {
                $invalid++;
                $status = 'invalid';
            } else {
                $valid++;
                $status = $action === 'skip' ? 'duplicate' : 'valid';
                $planned[$action]++;
            }

            $row->update([
                'external_key' => $externalKey,
                'row_hash' => $rowHash,
                'normalized_payload' => $normalized,
                'status' => $status,
                'planned_action' => $action,
                'validation_errors' => $errors ?: null,
                'published_entity_type' => null,
                'published_entity_id' => null,
            ]);
        }

        $summary = [
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'duplicate_rows' => $duplicates,
            'planned_inserts' => $planned['insert'],
            'planned_updates' => $planned['update'],
            'planned_skips' => $planned['skip'],
        ];

        $batch->update([
            'column_mapping' => $mapping,
            'mapping_defaults' => $defaults,
            'total_rows' => $valid + $invalid,
            'accepted_rows' => $valid,
            'rejected_rows' => $invalid,
            'duplicate_rows' => $duplicates,
            'status' => $invalid ? 'staged_with_errors' : 'validated',
            'reconciliation_status' => $invalid ? 'exception' : 'pending',
            'validation_summary' => $summary,
            'errors' => $invalid ? ['Terdapat baris yang belum lolos validasi. Perbaiki mapping/data sebelum publish.'] : null,
        ]);

        return $batch->fresh(['source', 'stagingRows']);
    }

    public function publish(DataImportBatch $batch, User $user, bool $allowPartial = false): DataImportBatch
    {
        if (! $batch->dataset_type) {
            throw ValidationException::withMessages(['batch' => 'Batch KPI manual lama tidak dapat dipublish melalui integration onboarding.']);
        }
        if ($batch->published_at) {
            throw ValidationException::withMessages(['batch' => 'Batch ini sudah pernah dipublikasikan.']);
        }

        $invalidCount = $batch->stagingRows()->where('status', 'invalid')->count();
        if ($invalidCount > 0 && ! $allowPartial) {
            throw ValidationException::withMessages([
                'batch' => "Masih ada {$invalidCount} baris invalid. Publish default bersifat all-or-nothing; perbaiki data atau pilih publish parsial secara eksplisit.",
            ]);
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $publishErrors = [];

        DB::transaction(function () use ($batch, $user, $allowPartial, &$inserted, &$updated, &$skipped, &$publishErrors): void {
            $lockedBatch = DataImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! $lockedBatch->dataset_type) {
                throw ValidationException::withMessages(['batch' => 'Batch KPI manual lama tidak dapat dipublish melalui integration onboarding.']);
            }
            $actor = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->hasPermission('integrations.manage') && $this->canManage($actor, (string) $lockedBatch->dataset_type), 403, 'Hak publish dataset sudah berubah. Muat ulang sesi dan coba lagi.');

            if ($lockedBatch->published_at) {
                throw ValidationException::withMessages(['batch' => 'Batch ini sudah pernah dipublikasikan.']);
            }

            $lockedInvalidCount = $lockedBatch->stagingRows()->where('status', 'invalid')->count();
            if ($lockedInvalidCount > 0 && ! $allowPartial) {
                throw ValidationException::withMessages([
                    'batch' => "Masih ada {$lockedInvalidCount} baris invalid. Publish default bersifat all-or-nothing; perbaiki data atau pilih publish parsial secara eksplisit.",
                ]);
            }

            foreach ($lockedBatch->stagingRows()->orderBy('row_number')->lockForUpdate()->get() as $row) {
                if ($row->status === 'invalid') {
                    continue;
                }
                if ($row->planned_action === 'skip') {
                    $link = IntegrationRecordLink::query()
                        ->where('data_source_id', $lockedBatch->data_source_id)
                        ->where('dataset_type', $lockedBatch->dataset_type)
                        ->where('external_key', $row->external_key)
                        ->first();
                    if (! $link || ! $this->linkEntityExists($link)) {
                        throw ValidationException::withMessages(['batch' => "Provenance link untuk baris {$row->row_number} tidak tersedia atau orphaned."]);
                    }
                    $link->update(['last_import_batch_id' => $lockedBatch->id, 'last_seen_at' => now()]);
                    $row->update([
                        'status' => 'skipped',
                        'published_entity_type' => $link->entity_type,
                        'published_entity_id' => $link->entity_id,
                    ]);
                    $skipped++;
                    continue;
                }

                try {
                    $link = IntegrationRecordLink::query()
                        ->where('data_source_id', $lockedBatch->data_source_id)
                        ->where('dataset_type', $lockedBatch->dataset_type)
                        ->where('external_key', $row->external_key)
                        ->first();
                    $authorityError = $this->authorityConflict(
                        $lockedBatch,
                        (string) $lockedBatch->dataset_type,
                        $row->normalized_payload ?? [],
                        $link,
                        true,
                    );
                    if ($authorityError) {
                        throw ValidationException::withMessages(['batch' => "Baris {$row->row_number}: {$authorityError}"]);
                    }

                    [$entity, $actualAction] = $this->publishRow($lockedBatch, $row, $actor);
                    IntegrationRecordLink::updateOrCreate(
                        [
                            'data_source_id' => $lockedBatch->data_source_id,
                            'dataset_type' => $lockedBatch->dataset_type,
                            'external_key' => $row->external_key,
                        ],
                        [
                            'entity_type' => $entity::class,
                            'entity_id' => $entity->getKey(),
                            'row_hash' => $row->row_hash,
                            'last_import_batch_id' => $lockedBatch->id,
                            'last_seen_at' => now(),
                        ]
                    );
                    $row->update([
                        'status' => 'published',
                        'planned_action' => $actualAction,
                        'published_entity_type' => $entity::class,
                        'published_entity_id' => $entity->getKey(),
                        'validation_errors' => null,
                    ]);
                    $actualAction === 'update' ? $updated++ : $inserted++;
                } catch (\Throwable $exception) {
                    if (! $allowPartial) {
                        throw $exception;
                    }
                    report($exception);
                    $reference = 'PUB-'.$lockedBatch->id.'-'.$row->row_number.'-'.Str::upper(Str::random(6));
                    $publicMessage = "Baris {$row->row_number}: gagal dipublikasikan. Referensi {$reference}.";
                    $publishErrors[] = $publicMessage;
                    $row->update([
                        'status' => 'publish_error',
                        'validation_errors' => [$publicMessage],
                    ]);
                }
            }

            $remainingErrors = $lockedBatch->stagingRows()->whereIn('status', ['invalid', 'publish_error'])->count();
            $lockedBatch->update([
                'inserted_rows' => $inserted,
                'updated_rows' => $updated,
                'skipped_rows' => $skipped,
                'status' => $remainingErrors ? 'published_with_errors' : 'published',
                'reconciliation_status' => $remainingErrors ? 'exception' : 'reconciled',
                'published_at' => now(),
                'published_by' => $actor->id,
                'errors' => $publishErrors ?: ($remainingErrors ? ['Sebagian baris belum dipublikasikan.'] : null),
            ]);

            $this->audit($actor, 'publish_integration', $lockedBatch, [
                'reference' => $lockedBatch->reference,
                'dataset_type' => $lockedBatch->dataset_type,
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $remainingErrors,
                'allow_partial' => $allowPartial,
            ]);
        });

        return $batch->fresh(['source', 'publisher:id,name', 'stagingRows']);
    }

    private function publishRow(DataImportBatch $batch, IntegrationStagingRow $row, User $user): array
    {
        $data = $row->normalized_payload ?? [];
        $link = IntegrationRecordLink::query()
            ->where('data_source_id', $batch->data_source_id)
            ->where('dataset_type', $batch->dataset_type)
            ->where('external_key', $row->external_key)
            ->first();

        return match ($batch->dataset_type) {
            'certification_schemes' => $this->publishScheme($data, $link),
            'tuks' => $this->publishTuk($data, $link),
            'assessors' => $this->publishAssessor($data, $link),
            'certification_batches' => $this->publishCertificationBatch($data, $link, $user),
            'certificate_issuances' => $this->publishCertificateIssuance($data, $user),
            'finance_invoices' => $this->publishFinanceInvoice($data, $user),
            'finance_payments' => $this->publishFinancePayment($data, $user),
            'financial_records' => $this->publishFinancialRecord($data, $user),
            'it_services' => $this->publishItService($data, $link),
            'it_incidents' => $this->publishItIncident($data, $link, $user),
            'data_quality_runs' => $this->publishDataQualityRun($data, $user),
            default => throw ValidationException::withMessages(['dataset_type' => 'Publisher dataset belum tersedia.']),
        };
    }

    private function publishScheme(array $data, ?IntegrationRecordLink $link): array
    {
        $entity = $link ? CertificationScheme::find($link->entity_id) : CertificationScheme::where('code', $data['code'])->first();
        $action = $entity ? 'update' : 'insert';
        $entity ??= new CertificationScheme();
        $entity->fill(Arr::only($data, ['code', 'name', 'category', 'units_count', 'is_active', 'valid_until', 'evidence_reference']))->save();
        return [$entity, $action];
    }

    private function publishTuk(array $data, ?IntegrationRecordLink $link): array
    {
        $entity = $link ? Tuk::find($link->entity_id) : Tuk::where('code', $data['code'])->first();
        $action = $entity ? 'update' : 'insert';
        $entity ??= new Tuk();
        $entity->fill(Arr::only($data, ['code', 'name', 'city', 'status', 'monthly_capacity', 'verification_valid_until', 'evidence_reference']))->save();
        return [$entity, $action];
    }

    private function publishAssessor(array $data, ?IntegrationRecordLink $link): array
    {
        $entity = $link ? Assessor::find($link->entity_id) : Assessor::where('registration_no', $data['registration_no'])->first();
        $action = $entity ? 'update' : 'insert';
        $entity ??= new Assessor();
        $entity->fill(Arr::only($data, ['registration_no', 'name', 'specialization', 'status', 'valid_until']))->save();
        return [$entity, $action];
    }

    private function publishCertificationBatch(array $data, ?IntegrationRecordLink $link, User $user): array
    {
        $scheme = CertificationScheme::where('code', $data['scheme_code'])->whereNull('archived_at')->where('is_active', true)->lockForUpdate()->firstOrFail();
        $tuk = Tuk::where('code', $data['tuk_code'])->whereNull('archived_at')->where('status', 'active')->lockForUpdate()->firstOrFail();
        $assessor = empty($data['assessor_registration_no']) ? null : Assessor::where('registration_no', $data['assessor_registration_no'])->whereNull('archived_at')->whereIn('status', ['active', 'expiring'])->lockForUpdate()->firstOrFail();
        $entity = $link ? CertificationBatch::find($link->entity_id) : CertificationBatch::where('code', $data['code'])->first();
        $action = $entity ? 'update' : 'insert';
        $entity ??= new CertificationBatch();
        $before = $entity->exists ? $entity->only([
            'status', 'passed', 'failed', 'pending', 'assessment_completed_at', 'decision_at',
            'certificate_due_at', 'completed_at', 'certificates_issued', 'issued_on_time',
        ]) : null;

        $status = (string) ($data['status'] ?? 'planned');
        $total = (int) $data['total_assesi'];
        $passed = (int) ($data['passed'] ?? 0);
        $failed = (int) ($data['failed'] ?? 0);
        $pending = ($data['pending'] ?? null) === null
            ? max(0, $total - $passed - $failed)
            : (int) $data['pending'];
        $assessmentDate = CarbonImmutable::parse($data['assessment_date'])->startOfDay();
        $assessmentCompletedAt = ! empty($data['assessment_completed_at']) ? CarbonImmutable::parse($data['assessment_completed_at']) : null;
        $decisionAt = ! empty($data['decision_at']) ? CarbonImmutable::parse($data['decision_at']) : null;
        $completedAt = ! empty($data['completed_at']) ? CarbonImmutable::parse($data['completed_at']) : null;

        if ($entity->exists) {
            $stages = ['planned', 'document_review', 'assessment', 'decision', 'completed'];
            $currentStage = array_search($entity->status, $stages, true);
            $requestedStage = array_search($status, $stages, true);
            if ($currentStage !== false && $requestedStage !== false && $requestedStage < $currentStage) {
                throw ValidationException::withMessages(['status' => 'Import tidak boleh mengembalikan lifecycle batch sertifikasi ke tahap sebelumnya.']);
            }
            if ($currentStage !== false && $requestedStage !== false && $requestedStage > ($currentStage + 1)) {
                throw ValidationException::withMessages(['status' => 'Import lifecycle batch harus berurutan dan tidak boleh melompati tahap.']);
            }
        }

        if (($passed + $failed + $pending) !== $total) {
            throw ValidationException::withMessages(['total_assesi' => 'passed + failed + pending harus sama dengan total_assesi.']);
        }
        if (in_array($status, ['decision', 'completed'], true) && $pending !== 0) {
            throw ValidationException::withMessages(['pending' => 'Batch decision/completed tidak boleh memiliki peserta pending.']);
        }
        if ($assessmentCompletedAt && $assessmentCompletedAt->lt($assessmentDate)) {
            throw ValidationException::withMessages(['assessment_completed_at' => 'Waktu selesai asesmen tidak boleh lebih awal dari tanggal asesmen.']);
        }
        if ($decisionAt && $decisionAt->lt($assessmentDate)) {
            throw ValidationException::withMessages(['decision_at' => 'Waktu keputusan tidak boleh lebih awal dari tanggal asesmen.']);
        }
        if ($assessmentCompletedAt && $decisionAt && $decisionAt->lt($assessmentCompletedAt)) {
            throw ValidationException::withMessages(['decision_at' => 'Waktu keputusan tidak boleh lebih awal dari selesainya asesmen.']);
        }
        if (in_array($status, ['decision', 'completed'], true) && (! $assessmentCompletedAt || ! $decisionAt)) {
            throw ValidationException::withMessages(['decision_at' => 'Batch decision/completed wajib memiliki assessment_completed_at dan decision_at.']);
        }
        if ($status !== 'completed' && $completedAt) {
            throw ValidationException::withMessages(['completed_at' => 'completed_at hanya boleh diisi ketika status batch completed.']);
        }

        $issued = $entity->exists ? (int) $entity->issuances()->sum('issued_count') : 0;
        $earliestIssuance = $entity->exists ? $entity->issuances()->min('issued_at') : null;
        $latestIssuance = $entity->exists ? $entity->issuances()->max('issued_at') : null;
        if ($passed < $issued) {
            throw ValidationException::withMessages(['passed' => 'Jumlah kompeten tidak boleh lebih kecil dari sertifikat yang sudah diterbitkan.']);
        }
        if ($earliestIssuance && $decisionAt && $decisionAt->gt(CarbonImmutable::parse($earliestIssuance))) {
            throw ValidationException::withMessages(['decision_at' => 'Waktu keputusan tidak dapat dipindahkan setelah waktu sertifikat yang sudah diterbitkan.']);
        }
        if ($status === 'completed') {
            if ($issued < $passed) {
                throw ValidationException::withMessages(['status' => 'Batch hanya boleh diimpor sebagai completed setelah seluruh sertifikat peserta kompeten tercatat pada issuance ledger.']);
            }
            if (! $completedAt) {
                throw ValidationException::withMessages(['completed_at' => 'Batch completed wajib memiliki completed_at.']);
            }
            if ($decisionAt && $completedAt->lt($decisionAt)) {
                throw ValidationException::withMessages(['completed_at' => 'Waktu selesai batch tidak boleh lebih awal dari keputusan.']);
            }
            if ($latestIssuance && $completedAt->lt(CarbonImmutable::parse($latestIssuance))) {
                throw ValidationException::withMessages(['completed_at' => 'Waktu selesai batch tidak boleh lebih awal dari penerbitan sertifikat terakhir.']);
            }
        }

        // Certificate SLA is system-derived. A legacy/source deadline may be supplied
        // for provenance, but it must never override the NADI 30-day policy clock.
        $certificateDueAt = $decisionAt?->addDays(30);

        $entity->fill([
            'code' => $data['code'],
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessor_id' => $assessor?->id,
            'assessment_date' => $data['assessment_date'],
            'total_assesi' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'pending' => $pending,
            'revenue' => $data['revenue'] ?? 0,
            'status' => $status,
            'assessment_completed_at' => $assessmentCompletedAt,
            'decision_at' => $decisionAt,
            'certificate_due_at' => $certificateDueAt,
            'completed_at' => $status === 'completed' ? $completedAt : null,
        ])->save();

        $issuedOnTime = $entity->certificate_due_at
            ? (int) $entity->issuances()->where('issued_at', '<=', $entity->certificate_due_at)->sum('issued_count')
            : 0;
        $entity->update([
            'certificates_issued' => $issued,
            'issued_on_time' => min($issuedOnTime, $passed),
        ]);
        $this->audit($user, 'integration_upsert_certification_batch', $entity, [
            'before' => $before,
            'after' => $entity->fresh()->only([
                'status', 'passed', 'failed', 'pending', 'assessment_completed_at', 'decision_at',
                'certificate_due_at', 'completed_at', 'certificates_issued', 'issued_on_time',
            ]),
        ]);

        return [$entity, $action];
    }

    private function publishCertificateIssuance(array $data, User $user): array
    {
        $batch = CertificationBatch::where('code', $data['batch_code'])->lockForUpdate()->firstOrFail();
        if (! $batch->decision_at || ! $batch->certificate_due_at || $batch->passed <= 0) {
            throw ValidationException::withMessages(['batch_code' => 'Penerbitan hanya dapat diimpor setelah keputusan kompetensi final dan deadline sertifikat tersedia.']);
        }
        if (CarbonImmutable::parse($data['issued_at'])->lt($batch->decision_at)) {
            throw ValidationException::withMessages(['issued_at' => 'Waktu penerbitan tidak boleh lebih awal dari waktu keputusan.']);
        }
        $alreadyIssued = (int) $batch->issuances()->sum('issued_count');
        if ($alreadyIssued + (int) $data['issued_count'] > (int) $batch->passed) {
            throw ValidationException::withMessages(['issued_count' => 'Jumlah sertifikat melebihi total peserta kompeten pada batch.']);
        }
        if (CertificateIssuance::where('reference', $data['reference'])->exists()) {
            throw ValidationException::withMessages(['reference' => 'Referensi penerbitan sertifikat sudah ada.']);
        }
        $entity = CertificateIssuance::create([
            'certification_batch_id' => $batch->id,
            'issued_count' => $data['issued_count'],
            'issued_at' => $data['issued_at'],
            'reference' => $data['reference'],
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $user->id,
        ]);
        $issued = (int) $batch->issuances()->sum('issued_count');
        $onTime = (int) $batch->issuances()
            ->where('issued_at', '<=', $batch->certificate_due_at)
            ->sum('issued_count');
        $batch->update(['certificates_issued' => $issued, 'issued_on_time' => $onTime]);
        return [$entity, 'insert'];
    }

    private function publishFinanceInvoice(array $data, User $user): array
    {
        if (FinanceInvoice::where('invoice_number', $data['invoice_number'])->exists()) {
            throw ValidationException::withMessages(['invoice_number' => 'Invoice sudah ada. Perubahan invoice existing harus memakai workflow koreksi/void.']);
        }
        $invoice = FinanceInvoice::create([
            ...Arr::only($data, ['invoice_number', 'issued_on', 'due_on', 'customer_name', 'category', 'department_code', 'amount', 'description']),
            'status' => 'issued',
            'created_by' => $user->id,
        ]);
        $revenue = FinancialRecord::create([
            'recorded_on' => $data['issued_on'],
            'type' => 'revenue',
            'entry_kind' => 'normal',
            'category' => $data['category'],
            'department_code' => $data['department_code'] ?? 'finance',
            'amount' => $data['amount'],
            'description' => $data['description'],
            'reference' => 'INV-'.$invoice->invoice_number,
            'source_type' => 'integration_invoice',
            'source_id' => $invoice->id,
            'recorded_by' => $user->id,
        ]);
        $invoice->update(['revenue_record_id' => $revenue->id]);
        return [$invoice, 'insert'];
    }

    private function publishFinancePayment(array $data, User $user): array
    {
        $invoice = FinanceInvoice::where('invoice_number', $data['invoice_number'])->lockForUpdate()->firstOrFail();
        if ($invoice->status === 'void') {
            throw ValidationException::withMessages(['invoice_number' => 'Invoice void tidak dapat menerima pembayaran.']);
        }
        if (CarbonImmutable::parse($data['paid_on'])->lt($invoice->issued_on)) {
            throw ValidationException::withMessages(['paid_on' => 'Tanggal pembayaran lebih awal dari tanggal invoice.']);
        }
        $paid = (float) $invoice->payments()->whereNull('reversed_at')->sum('amount');
        $outstanding = max(0, (float) $invoice->amount - $paid);
        if ((float) $data['amount'] > $outstanding + 0.005) {
            throw ValidationException::withMessages(['amount' => 'Pembayaran melebihi saldo piutang invoice.']);
        }
        $entity = FinancePayment::create([
            'finance_invoice_id' => $invoice->id,
            'paid_on' => $data['paid_on'],
            'amount' => $data['amount'],
            'reference' => $data['reference'],
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $user->id,
        ]);
        $remaining = max(0, $outstanding - (float) $data['amount']);
        $invoice->update(['status' => $remaining <= 0.005 ? 'paid' : 'partially_paid']);
        return [$entity, 'insert'];
    }

    private function publishFinancialRecord(array $data, User $user): array
    {
        if (FinancialRecord::where('reference', $data['reference'])->exists()) {
            throw ValidationException::withMessages(['reference' => 'Referensi ledger sudah ada. Koreksi ledger existing wajib melalui reversal.']);
        }
        $entity = FinancialRecord::create([
            ...Arr::only($data, ['recorded_on', 'type', 'category', 'department_code', 'amount', 'description', 'reference']),
            'entry_kind' => 'normal',
            'source_type' => 'integration',
            'recorded_by' => $user->id,
        ]);
        return [$entity, 'insert'];
    }

    private function publishItService(array $data, ?IntegrationRecordLink $link): array
    {
        $entity = $link ? ItService::find($link->entity_id) : ItService::where('name', $data['name'])->first();
        $action = $entity ? 'update' : 'insert';
        $entity ??= new ItService();
        $payload = Arr::only($data, ['name', 'owner', 'target_uptime', 'monitoring_started_at', 'status']);
        if ($action === 'insert' && empty($payload['monitoring_started_at'])) {
            $payload['monitoring_started_at'] = now();
        }
        $entity->fill($payload)->save();
        return [$entity, $action];
    }

    private function publishItIncident(array $data, ?IntegrationRecordLink $link, User $user): array
    {
        $service = ItService::where('name', $data['service_name'])->whereNull('archived_at')->firstOrFail();
        $entity = $link ? ItIncident::find($link->entity_id) : ItIncident::where('reference', $data['reference'])->first();
        $action = $entity ? 'update' : 'insert';
        $entity ??= new ItIncident();
        $before = $entity->exists ? $entity->only([
            'status', 'started_at', 'acknowledged_at', 'resolved_at', 'summary',
            'resolution_summary', 'root_cause', 'resolved_by',
        ]) : null;

        $status = (string) $data['status'];
        $startedAt = CarbonImmutable::parse($data['started_at']);
        $acknowledgedAt = ! empty($data['acknowledged_at']) ? CarbonImmutable::parse($data['acknowledged_at']) : null;
        $resolvedAt = ! empty($data['resolved_at']) ? CarbonImmutable::parse($data['resolved_at']) : null;

        if ($entity->exists) {
            $stages = ['open', 'investigating', 'resolved'];
            $currentStage = array_search($entity->status, $stages, true);
            $requestedStage = array_search($status, $stages, true);
            if ($currentStage !== false && $requestedStage !== false && $requestedStage < $currentStage) {
                throw ValidationException::withMessages(['status' => 'Import tidak boleh mengembalikan lifecycle insiden IT ke tahap sebelumnya.']);
            }
            if ($currentStage !== false && $requestedStage !== false && $requestedStage > ($currentStage + 1)) {
                throw ValidationException::withMessages(['status' => 'Import lifecycle insiden IT harus berurutan dan tidak boleh melompati tahap.']);
            }
        }

        if ($status === 'open' && $acknowledgedAt) {
            throw ValidationException::withMessages(['acknowledged_at' => 'Insiden open belum boleh memiliki acknowledged_at. Gunakan status investigating.']);
        }
        if (in_array($status, ['investigating', 'resolved'], true) && ! $acknowledgedAt) {
            throw ValidationException::withMessages(['acknowledged_at' => 'Insiden investigating/resolved wajib memiliki acknowledged_at.']);
        }
        if ($acknowledgedAt && $acknowledgedAt->lt($startedAt)) {
            throw ValidationException::withMessages(['acknowledged_at' => 'Waktu acknowledge tidak boleh lebih awal dari waktu mulai insiden.']);
        }
        if ($status === 'resolved' && (! $resolvedAt || empty($data['resolution_summary']))) {
            throw ValidationException::withMessages(['resolved_at' => 'Insiden resolved wajib memiliki resolved_at dan resolution_summary.']);
        }
        if ($status !== 'resolved' && $resolvedAt) {
            throw ValidationException::withMessages(['resolved_at' => 'resolved_at hanya boleh diisi ketika status insiden resolved.']);
        }
        if ($resolvedAt && $resolvedAt->lt($startedAt)) {
            throw ValidationException::withMessages(['resolved_at' => 'Waktu selesai tidak boleh lebih awal dari waktu mulai insiden.']);
        }
        if ($acknowledgedAt && $resolvedAt && $resolvedAt->lt($acknowledgedAt)) {
            throw ValidationException::withMessages(['resolved_at' => 'Waktu selesai tidak boleh lebih awal dari waktu acknowledge.']);
        }

        $entity->fill([
            'it_service_id' => $service->id,
            'reference' => $data['reference'],
            'severity' => $data['severity'],
            'status' => $status,
            'started_at' => $startedAt,
            'acknowledged_at' => $acknowledgedAt,
            'resolved_at' => $status === 'resolved' ? $resolvedAt : null,
            'summary' => $data['summary'],
            'resolution_summary' => $status === 'resolved' ? ($data['resolution_summary'] ?? null) : null,
            'root_cause' => $data['root_cause'] ?? null,
            'resolved_by' => $status === 'resolved' ? $user->id : null,
        ])->save();
        $this->audit($user, 'integration_upsert_it_incident', $entity, [
            'before' => $before,
            'after' => $entity->fresh()->only([
                'status', 'started_at', 'acknowledged_at', 'resolved_at', 'summary',
                'resolution_summary', 'root_cause', 'resolved_by',
            ]),
        ]);
        return [$entity, $action];
    }

    private function publishDataQualityRun(array $data, User $user): array
    {
        if (DataQualityRun::where('reference', $data['reference'])->exists()) {
            throw ValidationException::withMessages(['reference' => 'Reference audit kualitas data sudah ada. Gunakan reference baru untuk assessment baru.']);
        }
        $entity = DataQualityRun::create([
            ...Arr::only($data, [
                'reference', 'dataset_name', 'source_system', 'assessed_at', 'total_records', 'valid_records',
                'missing_required_records', 'duplicate_records', 'freshness_failures', 'notes',
            ]),
            'recorded_by' => $user->id,
        ]);
        return [$entity, 'insert'];
    }

    private function linkEntityExists(IntegrationRecordLink $link): bool
    {
        $class = $link->entity_type;
        return class_exists($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)
            && $class::query()->whereKey($link->entity_id)->exists();
    }

    private function authorityConflict(
        DataImportBatch $batch,
        string $datasetType,
        array $payload,
        ?IntegrationRecordLink $link,
        bool $lockEntity = false,
    ): ?string {
        $query = match ($datasetType) {
            'certification_schemes' => $link
                ? CertificationScheme::query()->whereKey($link->entity_id)
                : CertificationScheme::query()->where('code', $payload['code'] ?? null),
            'tuks' => $link
                ? Tuk::query()->whereKey($link->entity_id)
                : Tuk::query()->where('code', $payload['code'] ?? null),
            'assessors' => $link
                ? Assessor::query()->whereKey($link->entity_id)
                : Assessor::query()->where('registration_no', $payload['registration_no'] ?? null),
            'certification_batches' => $link
                ? CertificationBatch::query()->whereKey($link->entity_id)
                : CertificationBatch::query()->where('code', $payload['code'] ?? null),
            'it_services' => $link
                ? ItService::query()->whereKey($link->entity_id)
                : ItService::query()->where('name', $payload['name'] ?? null),
            'it_incidents' => $link
                ? ItIncident::query()->whereKey($link->entity_id)
                : ItIncident::query()->where('reference', $payload['reference'] ?? null),
            default => null,
        };
        if (! $query) {
            return null;
        }
        if ($lockEntity) {
            $query->lockForUpdate();
        }
        $entity = $query->first();
        if (! $entity) {
            return null;
        }
        if (in_array($datasetType, ['certification_schemes', 'tuks', 'assessors', 'it_services'], true) && $entity->archived_at) {
            return 'Entity master sedang diarsipkan. Aktifkan kembali melalui Pusat Data sebelum menerima pembaruan integrasi.';
        }

        // When called from publish(), the entity row is locked first. All mutable
        // publishers use the same lock, so a lower-authority source cannot race a
        // higher-authority provenance link that is being committed concurrently.
        $maxAuthority = IntegrationRecordLink::query()
            ->where('entity_type', $entity::class)
            ->where('entity_id', $entity->getKey())
            ->join('data_sources', 'data_sources.id', '=', 'integration_record_links.data_source_id')
            ->max('data_sources.authority_rank');
        $currentAuthority = (int) (DataSource::whereKey($batch->data_source_id)->value('authority_rank') ?? 0);

        return $maxAuthority !== null && (int) $maxAuthority > $currentAuthority
            ? "Source authority rank {$currentAuthority} tidak boleh mengubah entity yang sudah dikuasai source authority rank {$maxAuthority}."
            : null;
    }

    private function immutableEntityExists(string $datasetType, array $payload): bool
    {
        return match ($datasetType) {
            'certificate_issuances' => CertificateIssuance::where('reference', $payload['reference'] ?? null)->exists(),
            'finance_invoices' => FinanceInvoice::where('invoice_number', $payload['invoice_number'] ?? null)->exists(),
            'finance_payments' => FinancePayment::where('reference', $payload['reference'] ?? null)->exists(),
            'financial_records' => FinancialRecord::where('reference', $payload['reference'] ?? null)->exists(),
            'data_quality_runs' => DataQualityRun::where('reference', $payload['reference'] ?? null)->exists(),
            default => false,
        };
    }

    private function validateRow(array $definition, array $payload): array
    {
        $rules = [];
        foreach ($definition['fields'] as $name => $field) {
            $rules[$name] = $field['rules'];
        }
        $validator = Validator::make($payload, $rules);
        $validator->after(function ($validator) use ($definition, $payload): void {
            $type = $definition['type'];
            if ($type === 'certification_batches') {
                $total = (int) ($payload['total_assesi'] ?? 0);
                $passed = (int) ($payload['passed'] ?? 0);
                $failed = (int) ($payload['failed'] ?? 0);
                $pending = ($payload['pending'] ?? '') === '' || $payload['pending'] === null ? max(0, $total - $passed - $failed) : (int) $payload['pending'];
                $status = $payload['status'] ?? null;
                if ($passed + $failed + $pending !== $total) {
                    $validator->errors()->add('total_assesi', 'passed + failed + pending harus sama dengan total_assesi.');
                }
                if (in_array($status, ['decision', 'completed'], true) && $pending !== 0) {
                    $validator->errors()->add('pending', 'Batch decision/completed tidak boleh memiliki peserta pending.');
                }
                if (in_array($status, ['decision', 'completed'], true) && empty($payload['decision_at'])) {
                    $validator->errors()->add('decision_at', 'Batch decision/completed wajib memiliki decision_at.');
                }
                if (in_array($status, ['decision', 'completed'], true) && empty($payload['assessment_completed_at'])) {
                    $validator->errors()->add('assessment_completed_at', 'Batch decision/completed wajib memiliki assessment_completed_at.');
                }
                if ($status === 'completed' && empty($payload['completed_at'])) {
                    $validator->errors()->add('completed_at', 'Batch completed wajib memiliki completed_at.');
                }
                try {
                    $assessmentDate = ! empty($payload['assessment_date']) ? CarbonImmutable::parse($payload['assessment_date'])->startOfDay() : null;
                    $assessmentCompletedAt = ! empty($payload['assessment_completed_at']) ? CarbonImmutable::parse($payload['assessment_completed_at']) : null;
                    $decisionAt = ! empty($payload['decision_at']) ? CarbonImmutable::parse($payload['decision_at']) : null;
                    $completedAt = ! empty($payload['completed_at']) ? CarbonImmutable::parse($payload['completed_at']) : null;
                    if ($assessmentDate && $assessmentCompletedAt && $assessmentCompletedAt->lt($assessmentDate)) {
                        $validator->errors()->add('assessment_completed_at', 'Waktu selesai asesmen tidak boleh lebih awal dari tanggal asesmen.');
                    }
                    if ($assessmentDate && $decisionAt && $decisionAt->lt($assessmentDate)) {
                        $validator->errors()->add('decision_at', 'Waktu keputusan tidak boleh lebih awal dari tanggal asesmen.');
                    }
                    if ($assessmentCompletedAt && $decisionAt && $decisionAt->lt($assessmentCompletedAt)) {
                        $validator->errors()->add('decision_at', 'Waktu keputusan tidak boleh lebih awal dari selesainya asesmen.');
                    }
                    if ($status === 'completed' && $decisionAt && $completedAt && $completedAt->lt($decisionAt)) {
                        $validator->errors()->add('completed_at', 'Waktu selesai batch tidak boleh lebih awal dari keputusan.');
                    }
                    if ($status !== 'completed' && $completedAt) {
                        $validator->errors()->add('completed_at', 'completed_at hanya boleh diisi ketika status batch completed.');
                    }
                } catch (\Throwable) {
                    // Field-level date rules already report malformed date inputs.
                }
            }
            if ($type === 'it_incidents') {
                $status = $payload['status'] ?? null;
                if ($status === 'open' && ! empty($payload['acknowledged_at'])) {
                    $validator->errors()->add('acknowledged_at', 'Insiden open belum boleh memiliki acknowledged_at.');
                }
                if (in_array($status, ['investigating', 'resolved'], true) && empty($payload['acknowledged_at'])) {
                    $validator->errors()->add('acknowledged_at', 'Insiden investigating/resolved wajib memiliki acknowledged_at.');
                }
                if ($status === 'resolved' && empty($payload['resolved_at'])) {
                    $validator->errors()->add('resolved_at', 'Insiden resolved wajib memiliki resolved_at.');
                }
                if ($status === 'resolved' && empty($payload['resolution_summary'])) {
                    $validator->errors()->add('resolution_summary', 'Insiden resolved wajib memiliki ringkasan penyelesaian.');
                }
                if ($status !== 'resolved' && ! empty($payload['resolved_at'])) {
                    $validator->errors()->add('resolved_at', 'resolved_at hanya boleh diisi ketika status insiden resolved.');
                }
                try {
                    $startedAt = ! empty($payload['started_at']) ? CarbonImmutable::parse($payload['started_at']) : null;
                    $acknowledgedAt = ! empty($payload['acknowledged_at']) ? CarbonImmutable::parse($payload['acknowledged_at']) : null;
                    $resolvedAt = ! empty($payload['resolved_at']) ? CarbonImmutable::parse($payload['resolved_at']) : null;
                    if ($startedAt && $acknowledgedAt && $acknowledgedAt->lt($startedAt)) {
                        $validator->errors()->add('acknowledged_at', 'Waktu acknowledge tidak boleh lebih awal dari waktu mulai insiden.');
                    }
                    if ($startedAt && $resolvedAt && $resolvedAt->lt($startedAt)) {
                        $validator->errors()->add('resolved_at', 'Waktu selesai tidak boleh lebih awal dari waktu mulai insiden.');
                    }
                    if ($acknowledgedAt && $resolvedAt && $resolvedAt->lt($acknowledgedAt)) {
                        $validator->errors()->add('resolved_at', 'Waktu selesai tidak boleh lebih awal dari waktu acknowledge.');
                    }
                } catch (\Throwable) {
                    // Field-level date rules already report malformed date inputs.
                }
            }
            if ($type === 'data_quality_runs') {
                $total = (int) ($payload['total_records'] ?? 0);
                $valid = (int) ($payload['valid_records'] ?? 0);
                if ($valid > $total) {
                    $validator->errors()->add('valid_records', 'valid_records tidak boleh melebihi total_records.');
                }
            }
        });

        $errors = $validator->errors()->all();
        if (! $errors) {
            $errors = array_merge($errors, $this->relationalErrors($definition['type'], $payload));
        }
        $externalKey = trim((string) ($payload[$definition['key_field']] ?? '')) ?: null;
        if (! $externalKey) {
            $errors[] = "Field key {$definition['key_field']} wajib tersedia untuk idempotency.";
        }

        return [$errors, $externalKey];
    }

    private function relationalErrors(string $type, array $payload): array
    {
        $errors = [];
        if ($type === 'certification_batches') {
            if (! CertificationScheme::where('code', $payload['scheme_code'] ?? null)->whereNull('archived_at')->where('is_active', true)->exists()) $errors[] = 'scheme_code tidak ditemukan di master skema.';
            if (! Tuk::where('code', $payload['tuk_code'] ?? null)->whereNull('archived_at')->where('status', 'active')->exists()) $errors[] = 'tuk_code tidak ditemukan di master TUK.';
            if (! empty($payload['assessor_registration_no']) && ! Assessor::where('registration_no', $payload['assessor_registration_no'])->whereNull('archived_at')->whereIn('status', ['active', 'expiring'])->exists()) $errors[] = 'assessor_registration_no tidak ditemukan.';
        }
        if ($type === 'certificate_issuances' && ! CertificationBatch::where('code', $payload['batch_code'] ?? null)->exists()) {
            $errors[] = 'batch_code tidak ditemukan.';
        }
        if ($type === 'finance_payments' && ! FinanceInvoice::where('invoice_number', $payload['invoice_number'] ?? null)->exists()) {
            $errors[] = 'invoice_number belum tersedia. Publish invoice terlebih dahulu.';
        }
        if ($type === 'it_incidents' && ! ItService::where('name', $payload['service_name'] ?? null)->whereNull('archived_at')->exists()) {
            $errors[] = 'service_name tidak ditemukan di master layanan IT.';
        }
        return $errors;
    }

    private function normalizeRow(array $definition, array $raw, array $mapping, array $defaults): array
    {
        $normalized = [];
        foreach ($definition['fields'] as $name => $field) {
            $sourceHeader = $mapping[$name] ?? null;
            $value = $sourceHeader ? ($raw[$sourceHeader] ?? null) : ($defaults[$name] ?? null);
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') $value = null;
            }
            if (($field['cast'] ?? null) === 'boolean' && $value !== null) {
                $value = in_array(Str::lower((string) $value), ['1', 'true', 'yes', 'y', 'ya', 'aktif', 'active'], true) ? 1 : 0;
            }
            $normalized[$name] = $value;
        }
        if ($definition['type'] === 'certification_batches' && $normalized['pending'] === null && $normalized['total_assesi'] !== null) {
            $normalized['pending'] = max(0, (int) $normalized['total_assesi'] - (int) ($normalized['passed'] ?? 0) - (int) ($normalized['failed'] ?? 0));
        }
        return $normalized;
    }

    private function resolveMapping(array $definition, array $headers, array $provided): array
    {
        if ($provided) {
            return $provided;
        }
        $normalizedHeaders = collect($headers)->mapWithKeys(fn ($header) => [Str::lower(trim($header)) => $header]);
        $mapping = [];
        foreach (array_keys($definition['fields']) as $field) {
            $mapping[$field] = $normalizedHeaders[Str::lower($field)] ?? null;
        }
        return $mapping;
    }

    private function validateMapping(array $definition, array $headers, array $mapping, array $defaults): void
    {
        $errors = [];
        foreach ($definition['fields'] as $name => $field) {
            $sourceHeader = $mapping[$name] ?? null;
            if ($sourceHeader !== null && ! in_array($sourceHeader, $headers, true)) {
                $errors[$name] = "Kolom sumber '{$sourceHeader}' tidak ditemukan.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'File tidak dapat dibaca.']);
        }
        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'CSV kosong atau header tidak dapat dibaca.']);
        }
        $headers = array_map(function ($header) {
            $header = trim((string) $header);
            return preg_replace('/^\xEF\xBB\xBF/', '', $header);
        }, $headers);
        if (count(array_unique($headers)) !== count($headers)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Header CSV mengandung nama kolom duplikat.']);
        }

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === []) continue;
            if (count($values) !== count($headers)) {
                $values = array_pad(array_slice($values, 0, count($headers)), count($headers), null);
            }
            $rows[] = array_combine($headers, $values);
            if (count($rows) > 5000) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'Satu batch maksimal 5.000 baris agar review dan publish tetap terkendali.']);
            }
        }
        fclose($handle);
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'CSV tidak memiliki baris data.']);
        }
        return [$headers, $rows];
    }

    private function rowHash(array $payload): string
    {
        ksort($payload);
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function audit(User $user, string $action, $entity, array $changes): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'changes' => $changes,
            'ip_address' => request()?->ip(),
        ]);
    }

    private function definitions(): array
    {
        $requiredString = fn (int $max = 255) => ['required', 'string', "max:{$max}"];
        $nullableString = fn (int $max = 500) => ['nullable', 'string', "max:{$max}"];

        return [
            'certification_schemes' => [
                'type' => 'certification_schemes', 'label' => 'Master Skema Sertifikasi', 'domain' => 'certification', 'mutable' => true,
                'description' => 'Onboarding daftar skema dan masa berlakunya.', 'key_field' => 'code',
                'fields' => [
                    'code' => ['label' => 'Kode skema', 'required' => true, 'rules' => $requiredString(80), 'sample' => 'SKM-001'],
                    'name' => ['label' => 'Nama skema', 'required' => true, 'rules' => $requiredString(180), 'sample' => 'Operator Produksi'],
                    'category' => ['label' => 'Kategori', 'required' => true, 'rules' => $requiredString(120), 'sample' => 'Produksi'],
                    'units_count' => ['label' => 'Jumlah unit', 'required' => true, 'rules' => ['required', 'integer', 'min:1'], 'sample' => '8'],
                    'is_active' => ['label' => 'Aktif', 'required' => true, 'rules' => ['required', 'boolean'], 'cast' => 'boolean', 'sample' => '1'],
                    'valid_until' => ['label' => 'Berlaku sampai', 'rules' => ['nullable', 'date_format:Y-m-d'], 'sample' => '2027-12-31'],
                    'evidence_reference' => ['label' => 'Referensi bukti', 'rules' => $nullableString(255), 'sample' => 'DOC-SKM-001'],
                ],
            ],
            'tuks' => [
                'type' => 'tuks', 'label' => 'Master TUK', 'domain' => 'certification', 'mutable' => true,
                'description' => 'Onboarding Tempat Uji Kompetensi dan status verifikasinya.', 'key_field' => 'code',
                'fields' => [
                    'code' => ['label' => 'Kode TUK', 'required' => true, 'rules' => $requiredString(80), 'sample' => 'TUK-JKT-01'],
                    'name' => ['label' => 'Nama TUK', 'required' => true, 'rules' => $requiredString(180), 'sample' => 'TUK Jakarta'],
                    'city' => ['label' => 'Kota', 'required' => true, 'rules' => $requiredString(120), 'sample' => 'Jakarta'],
                    'monthly_capacity' => ['label' => 'Kapasitas bulanan', 'required' => true, 'rules' => ['required', 'integer', 'min:1'], 'sample' => '250'],
                    'status' => ['label' => 'Status', 'required' => true, 'rules' => ['required', Rule::in(['active', 'maintenance', 'inactive'])], 'sample' => 'active'],
                    'verification_valid_until' => ['label' => 'Verifikasi sampai', 'rules' => ['nullable', 'date_format:Y-m-d'], 'sample' => '2027-12-31'],
                    'evidence_reference' => ['label' => 'Referensi bukti', 'rules' => $nullableString(255), 'sample' => 'VER-TUK-001'],
                ],
            ],
            'assessors' => [
                'type' => 'assessors', 'label' => 'Master Asesor', 'domain' => 'certification', 'mutable' => true,
                'description' => 'Onboarding asesor, bidang, status, dan validitas.', 'key_field' => 'registration_no',
                'fields' => [
                    'registration_no' => ['label' => 'Nomor registrasi', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'MET.001234'],
                    'name' => ['label' => 'Nama asesor', 'required' => true, 'rules' => $requiredString(180), 'sample' => 'Asesor Demo'],
                    'specialization' => ['label' => 'Spesialisasi', 'required' => true, 'rules' => $requiredString(180), 'sample' => 'Produksi'],
                    'status' => ['label' => 'Status', 'required' => true, 'rules' => ['required', Rule::in(['active', 'expiring', 'inactive'])], 'sample' => 'active'],
                    'valid_until' => ['label' => 'Berlaku sampai', 'required' => true, 'rules' => ['required', 'date_format:Y-m-d'], 'sample' => '2027-12-31'],
                ],
            ],
            'certification_batches' => [
                'type' => 'certification_batches', 'label' => 'Batch Sertifikasi', 'domain' => 'certification', 'mutable' => true,
                'description' => 'Onboarding transaksi batch asesmen berbasis kode master.', 'key_field' => 'code',
                'fields' => [
                    'code' => ['label' => 'Kode batch', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'BAT-2026-001'],
                    'scheme_code' => ['label' => 'Kode skema', 'required' => true, 'rules' => $requiredString(80), 'sample' => 'SKM-001'],
                    'tuk_code' => ['label' => 'Kode TUK', 'required' => true, 'rules' => $requiredString(80), 'sample' => 'TUK-JKT-01'],
                    'assessor_registration_no' => ['label' => 'Registrasi asesor', 'rules' => $nullableString(100), 'sample' => 'MET.001234'],
                    'assessment_date' => ['label' => 'Tanggal asesmen', 'required' => true, 'rules' => ['required', 'date_format:Y-m-d'], 'sample' => '2026-09-15'],
                    'total_assesi' => ['label' => 'Total asesi', 'required' => true, 'rules' => ['required', 'integer', 'min:1'], 'sample' => '20'],
                    'passed' => ['label' => 'Kompeten', 'rules' => ['nullable', 'integer', 'min:0'], 'sample' => '18'],
                    'failed' => ['label' => 'Belum kompeten', 'rules' => ['nullable', 'integer', 'min:0'], 'sample' => '2'],
                    'pending' => ['label' => 'Pending', 'rules' => ['nullable', 'integer', 'min:0'], 'sample' => '0'],
                    'revenue' => ['label' => 'Pendapatan batch', 'rules' => ['nullable', 'numeric', 'min:0'], 'sample' => '50000000'],
                    'status' => ['label' => 'Status', 'required' => true, 'rules' => ['required', Rule::in(['planned', 'document_review', 'assessment', 'decision', 'completed'])], 'sample' => 'decision'],
                    'assessment_completed_at' => ['label' => 'Asesmen selesai', 'rules' => ['nullable', 'date'], 'sample' => '2026-09-15 16:00:00'],
                    'decision_at' => ['label' => 'Tanggal keputusan', 'rules' => ['nullable', 'date'], 'sample' => '2026-09-17 10:00:00'],
                    'certificate_due_at' => ['label' => 'Deadline sertifikat', 'rules' => ['nullable', 'date'], 'sample' => '2026-10-17 23:59:59'],
                    'completed_at' => ['label' => 'Batch selesai', 'rules' => ['nullable', 'date'], 'sample' => '2026-09-17 11:00:00'],
                ],
            ],
            'certificate_issuances' => [
                'type' => 'certificate_issuances', 'label' => 'Penerbitan Sertifikat', 'domain' => 'certification', 'mutable' => false,
                'description' => 'Ledger penerbitan sertifikat. Record yang berubah harus dikoreksi secara terkontrol.', 'key_field' => 'reference',
                'fields' => [
                    'reference' => ['label' => 'Referensi penerbitan', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'CERT-ISS-001'],
                    'batch_code' => ['label' => 'Kode batch', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'BAT-2026-001'],
                    'issued_count' => ['label' => 'Jumlah terbit', 'required' => true, 'rules' => ['required', 'integer', 'min:1'], 'sample' => '18'],
                    'issued_at' => ['label' => 'Waktu terbit', 'required' => true, 'rules' => ['required', 'date'], 'sample' => '2026-09-25 10:00:00'],
                    'notes' => ['label' => 'Catatan', 'rules' => ['nullable', 'string', 'max:1000'], 'sample' => 'Sinkronisasi e-sertifikat'],
                ],
            ],
            'finance_invoices' => [
                'type' => 'finance_invoices', 'label' => 'Invoice Keuangan', 'domain' => 'finance', 'mutable' => false,
                'description' => 'Invoice akan otomatis mem-posting revenue ke ledger.', 'key_field' => 'invoice_number',
                'fields' => [
                    'invoice_number' => ['label' => 'Nomor invoice', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'INV-2026-001'],
                    'issued_on' => ['label' => 'Tanggal invoice', 'required' => true, 'rules' => ['required', 'date_format:Y-m-d'], 'sample' => '2026-09-01'],
                    'due_on' => ['label' => 'Jatuh tempo', 'required' => true, 'rules' => ['required', 'date_format:Y-m-d', 'after_or_equal:issued_on'], 'sample' => '2026-09-30'],
                    'customer_name' => ['label' => 'Pelanggan', 'required' => true, 'rules' => $requiredString(160), 'sample' => 'PT Contoh Migas'],
                    'category' => ['label' => 'Kategori', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'Jasa sertifikasi'],
                    'department_code' => ['label' => 'Kode divisi', 'rules' => $nullableString(30), 'sample' => 'finance'],
                    'amount' => ['label' => 'Nilai invoice', 'required' => true, 'rules' => ['required', 'numeric', 'gt:0'], 'sample' => '75000000'],
                    'description' => ['label' => 'Deskripsi', 'required' => true, 'rules' => $requiredString(255), 'sample' => 'Pelaksanaan sertifikasi batch September'],
                ],
            ],
            'finance_payments' => [
                'type' => 'finance_payments', 'label' => 'Pembayaran Invoice', 'domain' => 'finance', 'mutable' => false,
                'description' => 'Ledger pembayaran; koreksi existing dilakukan melalui reversal.', 'key_field' => 'reference',
                'fields' => [
                    'reference' => ['label' => 'Referensi pembayaran', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'PAY-2026-001'],
                    'invoice_number' => ['label' => 'Nomor invoice', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'INV-2026-001'],
                    'paid_on' => ['label' => 'Tanggal bayar', 'required' => true, 'rules' => ['required', 'date_format:Y-m-d'], 'sample' => '2026-09-20'],
                    'amount' => ['label' => 'Nilai pembayaran', 'required' => true, 'rules' => ['required', 'numeric', 'gt:0'], 'sample' => '50000000'],
                    'notes' => ['label' => 'Catatan', 'rules' => $nullableString(500), 'sample' => 'Transfer bank'],
                ],
            ],
            'financial_records' => [
                'type' => 'financial_records', 'label' => 'Ledger Keuangan', 'domain' => 'finance', 'mutable' => false,
                'description' => 'Pendapatan/beban/anggaran non-invoice. Koreksi menggunakan reversal.', 'key_field' => 'reference',
                'fields' => [
                    'reference' => ['label' => 'Referensi', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'LED-2026-001'],
                    'recorded_on' => ['label' => 'Tanggal', 'required' => true, 'rules' => ['required', 'date_format:Y-m-d'], 'sample' => '2026-09-10'],
                    'type' => ['label' => 'Tipe', 'required' => true, 'rules' => ['required', Rule::in(['revenue', 'expense', 'budget'])], 'sample' => 'expense'],
                    'category' => ['label' => 'Kategori', 'required' => true, 'rules' => $requiredString(120), 'sample' => 'Operasional'],
                    'department_code' => ['label' => 'Kode divisi', 'rules' => $nullableString(30), 'sample' => 'finance'],
                    'amount' => ['label' => 'Nilai', 'required' => true, 'rules' => ['required', 'numeric', 'gt:0'], 'sample' => '15000000'],
                    'description' => ['label' => 'Deskripsi', 'required' => true, 'rules' => $requiredString(255), 'sample' => 'Biaya operasional TUK'],
                ],
            ],
            'it_services' => [
                'type' => 'it_services', 'label' => 'Master Layanan IT', 'domain' => 'it', 'mutable' => true,
                'description' => 'Onboarding layanan digital yang dipantau.', 'key_field' => 'source_key',
                'fields' => [
                    'source_key' => ['label' => 'Kunci sumber', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'SVC-PORTAL'],
                    'name' => ['label' => 'Nama layanan', 'required' => true, 'rules' => $requiredString(160), 'sample' => 'Portal Sertifikasi'],
                    'owner' => ['label' => 'Pemilik layanan', 'required' => true, 'rules' => $requiredString(160), 'sample' => 'Tim Aplikasi'],
                    'target_uptime' => ['label' => 'Target uptime', 'required' => true, 'rules' => ['required', 'numeric', 'between:90,100'], 'sample' => '99.5'],
                    'monitoring_started_at' => ['label' => 'Mulai pemantauan SLA', 'rules' => ['nullable', 'date'], 'sample' => '2026-01-01'],
                    'status' => ['label' => 'Status', 'required' => true, 'rules' => ['required', Rule::in(['operational', 'degraded', 'maintenance'])], 'sample' => 'operational'],
                ],
            ],
            'it_incidents' => [
                'type' => 'it_incidents', 'label' => 'Insiden IT', 'domain' => 'it', 'mutable' => true,
                'description' => 'Onboarding insiden dan lifecycle pemulihan layanan.', 'key_field' => 'reference',
                'fields' => [
                    'reference' => ['label' => 'Referensi insiden', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'INC-2026-001'],
                    'service_name' => ['label' => 'Nama layanan', 'required' => true, 'rules' => $requiredString(160), 'sample' => 'Portal Sertifikasi'],
                    'severity' => ['label' => 'Severity', 'required' => true, 'rules' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])], 'sample' => 'high'],
                    'status' => ['label' => 'Status', 'required' => true, 'rules' => ['required', Rule::in(['open', 'investigating', 'resolved'])], 'sample' => 'resolved'],
                    'started_at' => ['label' => 'Mulai', 'required' => true, 'rules' => ['required', 'date'], 'sample' => '2026-09-20 10:00:00'],
                    'acknowledged_at' => ['label' => 'Diakui', 'rules' => ['nullable', 'date'], 'sample' => '2026-09-20 10:05:00'],
                    'resolved_at' => ['label' => 'Selesai', 'rules' => ['nullable', 'date', 'after_or_equal:started_at'], 'sample' => '2026-09-20 11:30:00'],
                    'summary' => ['label' => 'Ringkasan', 'required' => true, 'rules' => ['required', 'string', 'max:1000'], 'sample' => 'Gangguan autentikasi pengguna'],
                    'resolution_summary' => ['label' => 'Penyelesaian', 'rules' => ['nullable', 'string', 'max:2000'], 'sample' => 'Konfigurasi dipulihkan'],
                    'root_cause' => ['label' => 'Root cause', 'rules' => ['nullable', 'string', 'max:2000'], 'sample' => 'Konfigurasi tidak sinkron'],
                ],
            ],
            'data_quality_runs' => [
                'type' => 'data_quality_runs', 'label' => 'Audit Kualitas Data', 'domain' => 'it', 'mutable' => false,
                'description' => 'Evidence audit kualitas dataset untuk KPI IT-DATA.', 'key_field' => 'reference',
                'fields' => [
                    'reference' => ['label' => 'Referensi audit', 'required' => true, 'rules' => $requiredString(100), 'sample' => 'DQ-2026-001'],
                    'dataset_name' => ['label' => 'Dataset', 'required' => true, 'rules' => $requiredString(160), 'sample' => 'Batch Sertifikasi'],
                    'source_system' => ['label' => 'Sistem sumber', 'required' => true, 'rules' => $requiredString(160), 'sample' => 'Legacy Sertifikasi'],
                    'assessed_at' => ['label' => 'Waktu assessment', 'required' => true, 'rules' => ['required', 'date'], 'sample' => '2026-09-30 09:00:00'],
                    'total_records' => ['label' => 'Total record', 'required' => true, 'rules' => ['required', 'integer', 'min:1'], 'sample' => '1000'],
                    'valid_records' => ['label' => 'Record valid', 'required' => true, 'rules' => ['required', 'integer', 'min:0'], 'sample' => '980'],
                    'missing_required_records' => ['label' => 'Missing required', 'rules' => ['nullable', 'integer', 'min:0'], 'sample' => '10'],
                    'duplicate_records' => ['label' => 'Duplikat', 'rules' => ['nullable', 'integer', 'min:0'], 'sample' => '5'],
                    'freshness_failures' => ['label' => 'Freshness failure', 'rules' => ['nullable', 'integer', 'min:0'], 'sample' => '5'],
                    'notes' => ['label' => 'Catatan', 'rules' => ['nullable', 'string', 'max:1000'], 'sample' => 'Audit onboarding September'],
                ],
            ],
        ];
    }
}
