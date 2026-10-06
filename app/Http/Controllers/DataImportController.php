<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataImportBatch;
use App\Models\DataSource;
use App\Models\KpiDefinition;
use App\Models\KpiMeasurement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataImportController extends Controller
{
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['kpi_code', 'period', 'actual', 'notes']);
            fputcsv($output, ['CUSTOM-KPI', now()->startOfMonth()->toDateString(), '98', 'Ganti dengan kode KPI manual yang sudah dikonfigurasi']);
            fclose($output);
        }, 'template-kpi-lsp-migas.csv', ['Content-Type' => 'text/csv']);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'data_source_id' => ['nullable', 'integer', 'exists:data_sources,id'],
            'dataset_name' => ['nullable', 'string', 'max:160'],
        ]);

        if ($request->filled('data_source_id') && ! $request->user()->hasPermission('governance.provenance.manage')) {
            abort(403, 'Pemilihan sumber data terdaftar hanya dapat dilakukan oleh Pimpinan atau Mutu & Kepatuhan.');
        }

        $file = $request->file('file');
        $path = $file->getRealPath();
        $handle = @fopen($path, 'r');
        if (! is_resource($handle)) {
            throw ValidationException::withMessages(['file' => 'File CSV tidak dapat dibaca. Unggah ulang file yang valid.']);
        }

        $sha256 = hash_file('sha256', $path);
        $reference = 'IMP-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(6));
        $datasetName = $request->input('dataset_name', 'KPI Manual Measurements');
        $header = fgetcsv($handle);
        if (is_array($header) && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }

        $batch = DB::transaction(function () use ($request, $reference, $datasetName, $file, $sha256): DataImportBatch {
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $actor->is_active && $actor->hasPermission('data.import.manage'),
                403,
                'Hak impor data sudah berubah. Muat ulang sesi dan coba lagi.'
            );

            $sourceId = $request->integer('data_source_id') ?: null;
            if ($sourceId) {
                abort_unless(
                    $actor->hasPermission('governance.provenance.manage'),
                    403,
                    'Pemilihan sumber data terdaftar hanya dapat dilakukan oleh Pimpinan atau Mutu & Kepatuhan.'
                );
                $source = DataSource::query()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
                abort_unless($source->is_active, 422, 'Sumber data sudah tidak aktif. Pilih sumber data aktif dan coba lagi.');
            }

            return DataImportBatch::create([
                'reference' => $reference,
                'data_source_id' => $sourceId,
                'dataset_name' => $datasetName,
                'dataset_type' => 'kpi_manual_measurements',
                'file_name' => $file->getClientOriginalName(),
                'sha256' => $sha256,
                'imported_at' => now(),
                'status' => 'processing',
                'reconciliation_status' => 'pending',
                'created_by' => $actor->id,
            ]);
        });

        if ($header !== ['kpi_code', 'period', 'actual', 'notes']) {
            fclose($handle);
            DB::transaction(function () use ($request, $batch): void {
                $locked = DataImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $locked->update([
                    'status' => 'failed',
                    'reconciliation_status' => 'exception',
                    'errors' => ['Header CSV harus: kpi_code,period,actual,notes'],
                ]);
                $this->auditBatch($request, $locked);
            });

            abort(422, 'Header CSV harus: kpi_code,period,actual,notes');
        }

        $errors = [];
        $parsedRows = [];
        $rowNumber = 1;
        $totalRows = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($data === [null] || $data === []) {
                continue;
            }
            $totalRows++;
            if (count($data) !== 4) {
                $errors[] = "Baris {$rowNumber}: jumlah kolom harus empat.";
                continue;
            }

            $rowData = [
                'kpi_code' => $data[0],
                'period' => $data[1],
                'actual' => $data[2],
                'notes' => $data[3],
            ];
            $validator = Validator::make($rowData, [
                'kpi_code' => ['required', 'string', 'max:60'],
                'period' => ['required', 'date_format:Y-m-d'],
                'actual' => ['required', 'numeric'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);
            if ($validator->fails()) {
                $errors[] = "Baris {$rowNumber}: ".$validator->errors()->first();
                continue;
            }

            $parsedRows[] = ['row_number' => $rowNumber] + $validator->validated();
        }
        fclose($handle);

        $parseErrors = $errors;
        $imported = 0;
        try {
            DB::transaction(function () use ($request, $batch, $parsedRows, $totalRows, $parseErrors, &$errors, &$imported, $reference): void {
                // A deadlock retry re-executes this closure. Keep attempt-local counters so
                // retries cannot double-count rows that were rolled back by the database.
                $attemptErrors = $parseErrors;
                $attemptImported = 0;
                $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->is_active && $actor->hasPermission('data.import.manage'), 403, 'Hak impor data sudah berubah. Muat ulang sesi dan coba lagi.');
                if ($batch->data_source_id && ! $actor->hasPermission('governance.provenance.manage')) {
                    abort(403, 'Pemilihan sumber data terdaftar hanya dapat dilakukan oleh Pimpinan atau Mutu & Kepatuhan.');
                }

                $codes = collect($parsedRows)->pluck('kpi_code')->unique()->sort()->values();
                $kpis = KpiDefinition::query()
                    ->whereNull('archived_at')
                    ->whereIn('code', $codes)
                    ->orderBy('code')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('code');
                // Kpi Catalog mutations lock the parent KPI row before configuration changes,
                // so loading versions after the parent locks gives this import a stable snapshot.
                $kpis->load('configurations');

                // Idempotency is checked after the KPI parent locks are acquired. Two identical
                // uploads may create processing batches concurrently, but the second transaction
                // cannot mutate measurements after the first one commits successfully.
                $duplicate = DataImportBatch::query()
                    ->whereKeyNot($batch->id)
                    ->where('dataset_type', 'kpi_manual_measurements')
                    ->where('sha256', $batch->sha256)
                    ->when(
                        $batch->data_source_id,
                        fn ($query) => $query->where('data_source_id', $batch->data_source_id),
                        fn ($query) => $query->whereNull('data_source_id'),
                    )
                    ->whereIn('status', ['completed', 'completed_with_errors'])
                    ->orderByDesc('id')
                    ->first();
                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'file' => "File identik sudah diproses pada batch {$duplicate->reference}. Gunakan file baru bila terdapat koreksi data.",
                    ]);
                }

                foreach ($parsedRows as $row) {
                    /** @var KpiDefinition|null $kpi */
                    $kpi = $kpis->get($row['kpi_code']);
                    if (! $kpi) {
                        $attemptErrors[] = "Baris {$row['row_number']}: kode KPI tidak ditemukan atau sudah diarsipkan.";
                        continue;
                    }
                    if ($kpi->isSystemDerived()) {
                        $attemptErrors[] = "Baris {$row['row_number']}: KPI {$kpi->code} dihitung otomatis dari data operasional dan tidak menerima impor manual.";
                        continue;
                    }

                    $configuration = $kpi->configurationFor($row['period']);
                    if (($kpi->configurations->isNotEmpty() && ! $configuration) || ($configuration && ! $configuration->is_active)) {
                        $attemptErrors[] = "Baris {$row['row_number']}: KPI {$kpi->code} tidak aktif atau belum memiliki konfigurasi pada periode tersebut.";
                        continue;
                    }
                    if ($actor->role !== 'director' && $actor->department_id !== $kpi->department_id) {
                        $attemptErrors[] = "Baris {$row['row_number']} di luar hak akses.";
                        continue;
                    }

                    $measurement = KpiMeasurement::query()
                        ->where('kpi_definition_id', $kpi->id)
                        ->whereDate('period', $row['period'])
                        ->lockForUpdate()
                        ->first();
                    $beforeMeasurement = $measurement?->only([
                        'actual', 'target_snapshot', 'notes', 'source_type', 'source_reference', 'data_import_batch_id', 'recorded_by',
                    ]);
                    $payload = [
                        'actual' => $row['actual'],
                        'target_snapshot' => (float) ($configuration?->target ?? $kpi->target),
                        'notes' => $row['notes'] ?: null,
                        'source_type' => 'csv_import',
                        'source_reference' => $reference,
                        'data_import_batch_id' => $batch->id,
                        'recorded_by' => $actor->id,
                    ];
                    if ($measurement) {
                        $measurement->update($payload);
                    } else {
                        $measurement = KpiMeasurement::create($payload + [
                            'kpi_definition_id' => $kpi->id,
                            'period' => $row['period'],
                        ]);
                    }
                    AuditLog::create([
                        'user_id' => $actor->id,
                        'action' => $beforeMeasurement ? 'csv_update_measurement' : 'csv_create_measurement',
                        'entity_type' => KpiMeasurement::class,
                        'entity_id' => $measurement->id,
                        'changes' => [
                            'before' => $beforeMeasurement,
                            'after' => $measurement->fresh()->only([
                                'actual', 'target_snapshot', 'notes', 'source_type', 'source_reference', 'data_import_batch_id', 'recorded_by',
                            ]),
                            'import_batch_reference' => $reference,
                            'row_number' => $row['row_number'],
                        ],
                        'ip_address' => $request->ip(),
                    ]);
                    $attemptImported++;
                }

                $lockedBatch = DataImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $lockedBatch->update([
                    'total_rows' => $totalRows,
                    'accepted_rows' => $attemptImported,
                    'rejected_rows' => count($attemptErrors),
                    'status' => count($attemptErrors) ? 'completed_with_errors' : 'completed',
                    'reconciliation_status' => count($attemptErrors) ? 'exception' : 'reconciled',
                    'errors' => $attemptErrors ?: null,
                ]);
                $this->auditBatch($request, $lockedBatch);
                $errors = $attemptErrors;
                $imported = $attemptImported;
            }, 3);
        } catch (\Throwable $exception) {
            report($exception);
            $failureReference = $reference.'-ERR-'.Str::upper(Str::random(6));
            DB::transaction(function () use ($request, $batch, $failureReference): void {
                $locked = DataImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $locked->update([
                    'status' => 'failed',
                    'reconciliation_status' => 'exception',
                    'errors' => ["Import gagal karena kesalahan runtime. Referensi {$failureReference}."],
                ]);
                $this->auditBatch($request, $locked);
            });
            throw $exception;
        }

        return response()->json([
            'imported' => $imported,
            'rejected' => count($errors),
            'errors' => $errors,
            'import_batch' => $batch->fresh('source'),
        ]);
    }

    private function auditBatch(Request $request, DataImportBatch $batch): void
    {
        AuditLog::create([
            // The batch creator is immutable provenance. Use it rather than the current
            // session state so terminal/failure audit events retain the original actor
            // even if their role or account status changes while a long import runs.
            'user_id' => $batch->created_by,
            'action' => 'import',
            'entity_type' => DataImportBatch::class,
            'entity_id' => $batch->id,
            'changes' => [
                'reference' => $batch->reference,
                'file' => $batch->file_name,
                'sha256' => $batch->sha256,
                'dataset_name' => $batch->dataset_name,
                'accepted' => $batch->accepted_rows,
                'rejected' => $batch->rejected_rows,
                'status' => $batch->status,
                'reconciliation_status' => $batch->reconciliation_status,
            ],
            'ip_address' => $request->ip(),
        ]);
    }
}
