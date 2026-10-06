<?php

namespace Tests\Feature;

use App\Models\CertificationBatch;
use App\Models\CertificationScheme;
use App\Models\Department;
use App\Models\KpiDefinition;
use App\Models\DataImportBatch;
use App\Models\User;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class KpiCatalogLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_kpi_configuration_is_versioned_and_historical_dashboard_keeps_old_target(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $kpi = KpiDefinition::where('code', 'FIN-BUDGET')->firstOrFail();

        $this->actingAs($director)->postJson("/api/kpi-catalog/{$kpi->id}/configurations", [
            'target' => 7,
            'warning_threshold' => 9,
            'weight' => 11,
            'owner_name' => 'Kepala Keuangan',
            'effective_from' => '2026-09-01',
            'effective_until' => null,
            'is_active' => true,
            'change_reason' => 'Target direvisi berdasarkan RKAP September.',
        ])->assertCreated();

        $august = collect($this->actingAs($director)->getJson('/api/dashboard?year=2026&month=8')->assertOk()->json('overview.kpis'))->keyBy('code');
        $september = collect($this->actingAs($director)->getJson('/api/dashboard?year=2026&month=9')->assertOk()->json('overview.kpis'))->keyBy('code');

        $this->assertSame(5.0, (float) $august['FIN-BUDGET']['target']);
        $this->assertSame(7.0, (float) $september['FIN-BUDGET']['target']);
        $this->assertSame(11.0, (float) $september['FIN-BUDGET']['weight']);
        $this->assertDatabaseHas('kpi_configurations', [
            'kpi_definition_id' => $kpi->id,
            'effective_until' => '2026-08-31',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'version_kpi_configuration']);
    }

    public function test_department_head_cannot_manage_kpi_from_other_department(): void
    {
        $certificationHead = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $financeKpi = KpiDefinition::where('code', 'FIN-REV')->firstOrFail();

        $this->actingAs($certificationHead)->postJson("/api/kpi-catalog/{$financeKpi->id}/configurations", [
            'target' => 1300,
            'warning_threshold' => 1100,
            'weight' => 13,
            'owner_name' => 'Kepala Keuangan',
            'effective_from' => '2026-09-01',
            'is_active' => true,
            'change_reason' => 'Tidak boleh lintas divisi.',
        ])->assertForbidden();
    }

    public function test_custom_kpi_uses_configuration_target_snapshot(): void
    {
        $financeHead = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $finance = Department::where('code', 'finance')->firstOrFail();

        $created = $this->actingAs($financeHead)->postJson('/api/kpi-catalog', [
            'department_id' => $finance->id,
            'code' => 'FIN-COLLECTION',
            'name' => 'Collection Rate',
            'description' => 'Persentase nilai invoice yang tertagih.',
            'unit' => '%',
            'direction' => 'higher',
            'cadence' => 'monthly',
            'data_source' => 'Rekonsiliasi piutang',
            'target' => 95,
            'warning_threshold' => 90,
            'weight' => 5,
            'owner_name' => 'Kepala Keuangan',
            'effective_from' => '2026-09-01',
            'change_reason' => 'KPI collection ditambahkan untuk pengendalian piutang.',
        ])->assertCreated();

        $kpiId = $created->json('kpi.id');
        $this->actingAs($financeHead)->postJson("/api/kpis/{$kpiId}/measurements", [
            'period' => '2026-09-01',
            'actual' => 93,
        ])->assertCreated();

        $this->assertDatabaseHas('kpi_measurements', [
            'kpi_definition_id' => $kpiId,
            'target_snapshot' => 95,
            'actual' => 93,
        ]);

        $measurementId = \App\Models\KpiMeasurement::query()
            ->where('kpi_definition_id', $kpiId)
            ->whereDate('period', '2026-09-01')
            ->value('id');
        $this->assertNotNull($measurementId);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create_measurement',
            'entity_type' => \App\Models\KpiMeasurement::class,
            'entity_id' => $measurementId,
        ]);

        $this->actingAs($financeHead)->postJson("/api/kpis/{$kpiId}/measurements", [
            'period' => '2026-09-01',
            'actual' => 94,
        ])->assertOk();

        $audit = \App\Models\AuditLog::query()
            ->where('action', 'update_measurement')
            ->where('entity_type', \App\Models\KpiMeasurement::class)
            ->where('entity_id', $measurementId)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(93.0, (float) data_get($audit->changes, 'before.actual'));
        $this->assertSame(94.0, (float) data_get($audit->changes, 'after.actual'));
    }

    public function test_csv_import_uses_locked_custom_kpi_configuration_snapshot(): void
    {
        $financeHead = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $finance = Department::where('code', 'finance')->firstOrFail();

        $created = $this->actingAs($financeHead)->postJson('/api/kpi-catalog', [
            'department_id' => $finance->id,
            'code' => 'FIN-CSV-COLLECTION',
            'name' => 'CSV Collection Rate',
            'description' => 'KPI manual untuk regression test CSV snapshot.',
            'unit' => '%',
            'direction' => 'higher',
            'cadence' => 'monthly',
            'data_source' => 'CSV manual',
            'target' => 96,
            'warning_threshold' => 90,
            'weight' => 5,
            'owner_name' => 'Kepala Keuangan',
            'effective_from' => '2026-09-01',
            'change_reason' => 'Regression test import CSV.',
        ])->assertCreated();

        $kpiId = $created->json('kpi.id');
        $file = UploadedFile::fake()->createWithContent(
            'manual-kpi.csv',
            "kpi_code,period,actual,notes\nFIN-CSV-COLLECTION,2026-09-01,94,Import terkunci\n",
        );

        $response = $this->actingAs($financeHead)->postJson('/api/data/import', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('rejected', 0)
            ->assertJsonPath('import_batch.dataset_type', 'kpi_manual_measurements');

        $this->assertDatabaseHas('kpi_measurements', [
            'kpi_definition_id' => $kpiId,
            'period' => '2026-09-01',
            'actual' => 94,
            'target_snapshot' => 96,
            'source_type' => 'csv_import',
            'data_import_batch_id' => $response->json('import_batch.id'),
        ]);
        $this->assertSame('kpi_manual_measurements', DataImportBatch::findOrFail($response->json('import_batch.id'))->dataset_type);
    }

    public function test_identical_manual_kpi_csv_is_rejected_and_measurement_correction_is_audited(): void
    {
        $financeHead = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $finance = Department::where('code', 'finance')->firstOrFail();

        $created = $this->actingAs($financeHead)->postJson('/api/kpi-catalog', [
            'department_id' => $finance->id,
            'code' => 'FIN-CSV-IDEMPOTENT',
            'name' => 'CSV Idempotent KPI',
            'description' => 'KPI manual untuk idempotency import.',
            'unit' => '%',
            'direction' => 'higher',
            'cadence' => 'monthly',
            'data_source' => 'CSV manual',
            'target' => 95,
            'warning_threshold' => 90,
            'weight' => 3,
            'owner_name' => 'Kepala Keuangan',
            'effective_from' => '2026-09-01',
            'change_reason' => 'Regression idempotency CSV.',
        ])->assertCreated();

        $content = "kpi_code,period,actual,notes
FIN-CSV-IDEMPOTENT,2026-09-01,93,Import pertama
";
        $first = $this->actingAs($financeHead)->postJson('/api/data/import', [
            'file' => UploadedFile::fake()->createWithContent('idempotent-a.csv', $content),
        ])->assertOk()->assertJsonPath('imported', 1);

        $measurementId = \App\Models\KpiMeasurement::query()
            ->where('kpi_definition_id', $created->json('kpi.id'))
            ->whereDate('period', '2026-09-01')
            ->value('id');
        $this->assertNotNull($measurementId);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'csv_create_measurement',
            'entity_type' => \App\Models\KpiMeasurement::class,
            'entity_id' => $measurementId,
        ]);

        $this->actingAs($financeHead)->postJson('/api/data/import', [
            'file' => UploadedFile::fake()->createWithContent('idempotent-b.csv', $content),
        ])->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->assertSame(93.0, (float) \App\Models\KpiMeasurement::findOrFail($measurementId)->actual);

        $correctedContent = "kpi_code,period,actual,notes\nFIN-CSV-IDEMPOTENT,2026-09-01,94,Koreksi terkontrol\n";
        $this->actingAs($financeHead)->postJson('/api/data/import', [
            'file' => UploadedFile::fake()->createWithContent('idempotent-correction.csv', $correctedContent),
        ])->assertOk()->assertJsonPath('imported', 1);

        $this->assertSame(94.0, (float) \App\Models\KpiMeasurement::findOrFail($measurementId)->actual);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'csv_update_measurement',
            'entity_type' => \App\Models\KpiMeasurement::class,
            'entity_id' => $measurementId,
        ]);
        $this->assertSame('completed', DataImportBatch::findOrFail($first->json('import_batch.id'))->status);
    }

    public function test_archived_custom_kpi_rejects_new_measurement(): void
    {
        $financeHead = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $finance = Department::where('code', 'finance')->firstOrFail();

        $created = $this->actingAs($financeHead)->postJson('/api/kpi-catalog', [
            'department_id' => $finance->id,
            'code' => 'FIN-ARCHIVED-KPI',
            'name' => 'Archived KPI',
            'description' => 'KPI untuk regression test lifecycle arsip.',
            'unit' => '%',
            'direction' => 'higher',
            'cadence' => 'monthly',
            'data_source' => 'Manual test',
            'target' => 90,
            'warning_threshold' => 80,
            'weight' => 1,
            'owner_name' => 'Kepala Keuangan',
            'effective_from' => '2026-09-01',
            'change_reason' => 'Regression test.',
        ])->assertCreated();

        $kpi = KpiDefinition::findOrFail($created->json('kpi.id'));
        $kpi->forceFill(['archived_at' => now()])->save();

        $this->actingAs($financeHead)->postJson("/api/kpis/{$kpi->id}/measurements", [
            'period' => '2026-09-01',
            'actual' => 91,
        ])->assertUnprocessable()->assertJsonValidationErrors(['actual']);
    }

    public function test_master_data_can_be_updated_archived_and_restored_without_deletion(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $created = $this->actingAs($director)->postJson('/api/master-data/certification-schemes', [
            'code' => 'SK-ARCH-01',
            'name' => 'Skema Arsip Test',
            'category' => 'Okupasi',
            'units_count' => 5,
            'is_active' => true,
            'valid_until' => '2028-12-31',
            'evidence_reference' => 'DOC-001',
        ])->assertCreated();
        $id = $created->json('record.id');

        $this->actingAs($director)->patchJson("/api/master-data/certification-schemes/{$id}", [
            'name' => 'Skema Arsip Test Revisi',
            'category' => 'Okupasi',
            'units_count' => 6,
            'is_active' => true,
            'valid_until' => '2029-12-31',
            'evidence_reference' => 'DOC-002',
            'change_reason' => 'Perpanjangan dan revisi unit kompetensi.',
        ])->assertOk();

        $this->actingAs($director)->postJson("/api/master-data/certification-schemes/{$id}/archive", [
            'reason' => 'Tidak digunakan untuk batch baru.',
        ])->assertOk();
        $this->assertDatabaseHas('certification_schemes', ['id' => $id, 'is_active' => false]);
        $this->assertNotNull(CertificationScheme::findOrFail($id)->archived_at);

        $this->actingAs($director)->postJson("/api/master-data/certification-schemes/{$id}/restore", [
            'reason' => 'Skema kembali digunakan.',
        ])->assertOk();
        $this->assertNull(CertificationScheme::findOrFail($id)->archived_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'archive_master_data']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'restore_master_data']);
    }

    public function test_master_data_with_active_dependency_cannot_be_archived(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $batch = CertificationBatch::whereNot('status', 'completed')->firstOrFail();

        $this->actingAs($director)->postJson("/api/master-data/certification-schemes/{$batch->certification_scheme_id}/archive", [
            'reason' => 'Percobaan arsip dengan batch aktif.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['record']);
    }

    public function test_archived_scheme_cannot_be_used_for_new_certification_batch(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $scheme = CertificationScheme::query()->create([
            'code' => 'SK-ARCH-BATCH',
            'name' => 'Skema Archived Batch Test',
            'category' => 'Okupasi',
            'units_count' => 4,
            'is_active' => true,
        ]);
        $this->actingAs($director)->postJson("/api/master-data/certification-schemes/{$scheme->id}/archive", [
            'reason' => 'Tidak lagi tersedia untuk batch baru.',
        ])->assertOk();

        $catalog = $this->actingAs($director)->getJson('/api/certification')->assertOk()->json('catalogs');
        $tukId = $catalog['tuks'][0]['id'];
        $assessorId = $catalog['assessors'][0]['id'];

        $this->actingAs($director)->postJson('/api/certification/batches', [
            'code' => 'ASM-ARCHIVED-SCHEME',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tukId,
            'assessor_id' => $assessorId,
            'assessment_date' => '2026-09-30',
            'total_assesi' => 10,
            'revenue' => 10000000,
            'status' => 'planned',
        ])->assertUnprocessable()->assertJsonValidationErrors(['certification_scheme_id']);
    }

}
