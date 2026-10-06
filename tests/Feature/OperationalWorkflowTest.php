<?php

namespace Tests\Feature;

use App\Models\ActionItem;
use App\Models\Assessor;
use App\Models\AuditLog;
use App\Models\CertificateIssuance;
use App\Models\CertificationBatch;
use App\Models\CertificationScheme;
use App\Models\DataQualityRun;
use App\Models\Department;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancialRecord;
use App\Models\ItIncident;
use App\Models\ItService;
use App\Models\KpiDefinition;
use App\Models\Tuk;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OperationalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_department_head_dashboard_returns_only_own_actions_and_options(): void
    {
        $financeHead = User::where('email', 'keuangan@demo.test')->firstOrFail();

        $response = $this->actingAs($financeHead)->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonCount(1, 'actions')
            ->assertJsonPath('actions.0.department.code', 'finance')
            ->assertJsonCount(1, 'action_options.departments')
            ->assertJsonPath('action_options.departments.0.code', 'finance')
            ->assertJsonCount(3, 'action_options.kpis');
    }

    public function test_department_head_cannot_update_another_departments_action(): void
    {
        $financeHead = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $certificationDepartment = Department::where('code', 'certification')->firstOrFail();
        $certificationAction = ActionItem::where('department_id', $certificationDepartment->id)->firstOrFail();

        $this->actingAs($financeHead)
            ->patchJson("/api/actions/{$certificationAction->id}", ['status' => 'completed'])
            ->assertForbidden();

        $this->assertSame('in_progress', $certificationAction->fresh()->status);
    }

    public function test_certification_head_creates_batch_and_audit_record(): void
    {
        $certificationHead = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $scheme = CertificationScheme::firstOrFail();
        $tuk = Tuk::firstOrFail();
        $assessor = Assessor::firstOrFail();

        $response = $this->actingAs($certificationHead)->postJson('/api/certification/batches', [
            'code' => 'ASM-202610-01',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessor_id' => $assessor->id,
            'assessment_date' => '2026-10-15',
            'total_assesi' => 32,
            'revenue' => 136000000,
            'status' => 'planned',
        ]);

        $response->assertCreated()
            ->assertJsonPath('batch.code', 'ASM-202610-01')
            ->assertJsonPath('batch.pending', 32);
        $this->assertDatabaseHas('certification_batches', ['code' => 'ASM-202610-01', 'pending' => 32]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'create', 'entity_type' => 'App\\Models\\CertificationBatch']);
    }

    public function test_finance_record_rejects_non_iso_date(): void
    {
        $financeHead = User::where('email', 'keuangan@demo.test')->firstOrFail();

        $response = $this->actingAs($financeHead)->postJson('/api/finance/records', [
            'recorded_on' => '27/09/2026',
            'type' => 'expense',
            'category' => 'Operasional',
            'amount' => 250000,
            'description' => 'Biaya pengujian validasi.',
            'reference' => 'TRX-VALIDATION-01',
        ]);

        $response->assertUnprocessable()->assertInvalid(['recorded_on']);
        $this->assertDatabaseMissing('financial_records', ['reference' => 'TRX-VALIDATION-01']);
    }

    public function test_csv_import_rejects_invalid_period_and_records_audit_summary(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $file = UploadedFile::fake()->createWithContent(
            'kpi.csv',
            "kpi_code,period,actual,notes\nCERT-PASS,27/09/2026,95,Tanggal salah\n",
        );

        $response = $this->actingAs($director)->postJson('/api/data/import', ['file' => $file]);

        $response->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('rejected', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'import', 'entity_type' => 'App\\Models\\DataImportBatch']);
    }
    public function test_certification_lifecycle_records_decision_issuance_and_completion(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $scheme = CertificationScheme::firstOrFail();
        $tuk = Tuk::firstOrFail();
        $assessor = Assessor::firstOrFail();

        $created = $this->actingAs($head)->postJson('/api/certification/batches', [
            'code' => 'ASM-202610-LIFE',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessor_id' => $assessor->id,
            'assessment_date' => '2026-10-15',
            'total_assesi' => 10,
            'revenue' => 42500000,
            'status' => 'assessment',
        ])->assertCreated();

        $batchId = $created->json('batch.id');

        $this->actingAs($head)->patchJson("/api/certification/batches/{$batchId}", [
            'status' => 'decision',
            'passed' => 8,
            'failed' => 2,
            'assessment_completed_at' => '2026-10-15T17:00:00+07:00',
            'decision_at' => '2026-10-16T10:00:00+07:00',
        ])->assertOk()
            ->assertJsonPath('batch.passed', 8)
            ->assertJsonPath('batch.failed', 2)
            ->assertJsonPath('batch.pending', 0);

        $batch = CertificationBatch::findOrFail($batchId);
        $this->assertSame('2026-11-15', $batch->certificate_due_at->toDateString());

        $this->actingAs($head)->postJson("/api/certification/batches/{$batchId}/issuances", [
            'issued_count' => 5,
            'issued_at' => '2026-11-01T09:00:00+07:00',
            'reference' => 'CERT-LIFE-01',
        ])->assertCreated()
            ->assertJsonPath('batch.certificates_issued', 5)
            ->assertJsonPath('batch.issued_on_time', 5);

        $this->actingAs($head)->postJson("/api/certification/batches/{$batchId}/issuances", [
            'issued_count' => 1,
            'issued_at' => '2026-11-02T09:00:00+07:00',
            'reference' => 'CERT-LIFE-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reference']);

        $this->actingAs($head)->postJson("/api/certification/batches/{$batchId}/issuances", [
            'issued_count' => 3,
            'issued_at' => '2026-11-20T09:00:00+07:00',
            'reference' => 'CERT-LIFE-02',
        ])->assertCreated()
            ->assertJsonPath('batch.certificates_issued', 8)
            ->assertJsonPath('batch.issued_on_time', 5);

        $this->actingAs($head)->patchJson("/api/certification/batches/{$batchId}", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('batch.status', 'completed');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'certificate_issuance',
            'entity_type' => 'App\\Models\\CertificateIssuance',
        ]);
    }


    public function test_certification_decision_timestamp_cannot_move_after_existing_issuance(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $scheme = CertificationScheme::firstOrFail();
        $tuk = Tuk::firstOrFail();
        $assessor = Assessor::firstOrFail();

        $created = $this->actingAs($head)->postJson('/api/certification/batches', [
            'code' => 'ASM-202610-CHRONOLOGY',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessor_id' => $assessor->id,
            'assessment_date' => '2026-10-10',
            'total_assesi' => 2,
            'revenue' => 10000000,
            'status' => 'assessment',
        ])->assertCreated();

        $batchId = $created->json('batch.id');
        $this->actingAs($head)->patchJson("/api/certification/batches/{$batchId}", [
            'status' => 'decision',
            'passed' => 2,
            'failed' => 0,
            'assessment_completed_at' => '2026-10-10T17:00:00+07:00',
            'decision_at' => '2026-10-11T09:00:00+07:00',
        ])->assertOk();

        $this->actingAs($head)->postJson("/api/certification/batches/{$batchId}/issuances", [
            'issued_count' => 1,
            'issued_at' => '2026-10-15T09:00:00+07:00',
            'reference' => 'CERT-CHRONOLOGY-01',
        ])->assertCreated();

        $this->actingAs($head)->patchJson("/api/certification/batches/{$batchId}", [
            'status' => 'decision',
            'decision_at' => '2026-10-16T09:00:00+07:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['decision_at']);
    }

    public function test_decision_rejects_incomplete_outcome_totals(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $batch = CertificationBatch::where('status', 'assessment')->firstOrFail();

        $this->actingAs($head)->patchJson("/api/certification/batches/{$batch->id}", [
            'status' => 'decision',
            'passed' => 10,
            'failed' => 2,
            'assessment_completed_at' => now()->toIso8601String(),
            'decision_at' => now()->toIso8601String(),
        ])->assertUnprocessable()->assertInvalid(['passed']);
    }

    public function test_certification_kpis_are_derived_from_operational_ledger(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $director = User::where('role', 'director')->firstOrFail();
        $scheme = CertificationScheme::firstOrFail();
        $tuk = Tuk::firstOrFail();
        $assessor = Assessor::firstOrFail();

        $created = $this->actingAs($head)->postJson('/api/certification/batches', [
            'code' => 'ASM-202610-KPI',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessor_id' => $assessor->id,
            'assessment_date' => '2026-10-12',
            'total_assesi' => 10,
            'revenue' => 42500000,
            'status' => 'assessment',
        ])->assertCreated();

        $this->actingAs($head)->patchJson('/api/certification/batches/'.$created->json('batch.id'), [
            'status' => 'decision',
            'passed' => 8,
            'failed' => 2,
            'assessment_completed_at' => '2026-10-12T17:00:00+07:00',
            'decision_at' => '2026-10-13T10:00:00+07:00',
        ])->assertOk();

        $dashboard = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=10')->assertOk()->json('overview.kpis');
        $byCode = collect($dashboard)->keyBy('code');

        $this->assertSame(10.0, (float) $byCode['CERT-VOLUME']['actual']);
        $this->assertSame(80.0, (float) $byCode['CERT-PASS']['actual']);
        $this->assertTrue($byCode['CERT-VOLUME']['is_system_derived']);
        $this->assertSame('system_derived', $byCode['CERT-PASS']['source_type']);
    }

    public function test_system_derived_certification_kpi_cannot_be_overwritten_manually(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $kpi = KpiDefinition::where('code', 'CERT-VOLUME')->firstOrFail();

        $this->actingAs($director)->postJson("/api/kpis/{$kpi->id}/measurements", [
            'period' => '2026-09-01',
            'actual' => 9999,
        ])->assertUnprocessable()->assertInvalid(['actual']);
    }

    public function test_csv_import_rejects_system_derived_certification_kpi(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $file = UploadedFile::fake()->createWithContent(
            'kpi.csv',
            "kpi_code,period,actual,notes\nCERT-PASS,2026-09-01,99,Tidak boleh override\n",
        );

        $this->actingAs($director)->postJson('/api/data/import', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('rejected', 1);
    }

    public function test_finance_kpis_are_derived_and_warning_threshold_applies(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $response = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=9')->assertOk();
        $byCode = collect($response->json('overview.kpis'))->keyBy('code');

        $this->assertSame(1261.0, (float) $byCode['FIN-REV']['actual']);
        $this->assertSame(45.04, (float) $byCode['FIN-MARGIN']['actual']);
        $this->assertSame(10.0, (float) $byCode['FIN-BUDGET']['actual']);
        $this->assertSame(5.0, (float) $byCode['FIN-BUDGET']['target']);
        $this->assertSame(8.0, (float) $byCode['FIN-BUDGET']['warning_threshold']);
        $this->assertSame('critical', $byCode['FIN-BUDGET']['status']);
        $this->assertTrue($byCode['FIN-REV']['is_system_derived']);
    }

    public function test_finance_invoice_posts_revenue_and_payment_updates_receivable(): void
    {
        $head = User::where('email', 'keuangan@demo.test')->firstOrFail();

        $created = $this->actingAs($head)->postJson('/api/finance/invoices', [
            'invoice_number' => 'INV-TEST-001',
            'issued_on' => '2026-09-01',
            'due_on' => '2026-09-20',
            'customer_name' => 'PT Uji Finansial',
            'category' => 'Jasa sertifikasi',
            'department_code' => 'finance',
            'amount' => 100000000,
            'description' => 'Invoice pengujian workflow keuangan.',
        ])->assertCreated();

        $invoiceId = $created->json('invoice.id');
        $this->assertDatabaseHas('financial_records', [
            'type' => 'revenue',
            'source_type' => 'finance_invoice',
            'source_id' => $invoiceId,
            'amount' => 100000000,
        ]);

        $this->actingAs($head)->postJson("/api/finance/invoices/{$invoiceId}/payments", [
            'paid_on' => '2026-09-10',
            'amount' => 40000000,
            'reference' => 'PAY-TEST-001',
        ])->assertCreated()->assertJsonPath('invoice.status', 'partially_paid');

        $finance = $this->actingAs($head)->getJson('/api/finance?year=2026&month=9')->assertOk();
        $row = collect($finance->json('invoices'))->firstWhere('invoice_number', 'INV-TEST-001');
        $this->assertSame(60000000.0, (float) $row['outstanding']);
        $this->assertGreaterThanOrEqual(60000000.0, (float) $finance->json('summary.overdue_receivable'));
    }

    public function test_payment_cannot_exceed_invoice_outstanding(): void
    {
        $head = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $invoice = FinanceInvoice::where('status', 'issued')->firstOrFail();

        $this->actingAs($head)->postJson("/api/finance/invoices/{$invoice->id}/payments", [
            'paid_on' => '2026-09-29',
            'amount' => (float) $invoice->amount + 1,
            'reference' => 'PAY-OVER-001',
        ])->assertUnprocessable()->assertInvalid(['amount']);
    }

    public function test_manual_finance_record_is_reversed_without_deletion(): void
    {
        $head = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $created = $this->actingAs($head)->postJson('/api/finance/records', [
            'recorded_on' => '2026-09-20',
            'type' => 'expense',
            'category' => 'Koreksi audit',
            'amount' => 25000000,
            'description' => 'Transaksi yang akan direversal.',
            'reference' => 'EXP-REV-001',
        ])->assertCreated();
        $recordId = $created->json('record.id');

        $this->actingAs($head)->postJson("/api/finance/records/{$recordId}/reverse", [
            'recorded_on' => '2026-09-21',
            'reason' => 'Salah klasifikasi biaya.',
            'reference' => 'REV-EXP-001',
        ])->assertCreated();

        $this->assertDatabaseHas('financial_records', ['id' => $recordId, 'amount' => 25000000]);
        $this->assertDatabaseHas('financial_records', [
            'reversal_of_id' => $recordId,
            'entry_kind' => 'reversal',
            'amount' => 25000000,
        ]);
    }

    public function test_invoice_void_requires_payment_reversal_and_posts_revenue_reversal(): void
    {
        $head = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $invoice = FinanceInvoice::where('status', 'partially_paid')->firstOrFail();
        $payment = $invoice->payments()->whereNull('reversed_at')->firstOrFail();

        $this->actingAs($head)->postJson("/api/finance/invoices/{$invoice->id}/void", [
            'voided_on' => '2026-09-29',
            'reason' => 'Dokumen tagihan dibatalkan.',
        ])->assertUnprocessable()->assertInvalid(['invoice']);

        $this->actingAs($head)->postJson("/api/finance/payments/{$payment->id}/reverse", [
            'reason' => 'Pembayaran dialihkan ke invoice pengganti.',
        ])->assertOk();

        $this->actingAs($head)->postJson("/api/finance/invoices/{$invoice->id}/void", [
            'voided_on' => '2026-09-29',
            'reason' => 'Dokumen tagihan dibatalkan.',
        ])->assertOk()->assertJsonPath('invoice.status', 'void');

        $this->assertDatabaseHas('financial_records', [
            'reversal_of_id' => $invoice->revenue_record_id,
            'entry_kind' => 'reversal',
            'source_type' => 'invoice_void',
        ]);
    }

    public function test_system_derived_finance_kpi_cannot_be_overwritten_manually_or_by_csv(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $kpi = KpiDefinition::where('code', 'FIN-REV')->firstOrFail();

        $this->actingAs($director)->postJson("/api/kpis/{$kpi->id}/measurements", [
            'period' => '2026-09-01',
            'actual' => 9999,
        ])->assertUnprocessable()->assertInvalid(['actual']);

        $file = UploadedFile::fake()->createWithContent(
            'finance-kpi.csv',
            "kpi_code,period,actual,notes\nFIN-REV,2026-09-01,9999,Tidak boleh override\n",
        );
        $this->actingAs($director)->postJson('/api/data/import', ['file' => $file])
            ->assertOk()->assertJsonPath('imported', 0)->assertJsonPath('rejected', 1);
    }

    public function test_tuk_maintenance_status_is_accepted_end_to_end(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();

        $this->actingAs($head)->postJson('/api/master-data/tuks', [
            'code' => 'TUK-MAINT-01',
            'name' => 'TUK Pemeliharaan',
            'city' => 'Jakarta',
            'status' => 'maintenance',
            'monthly_capacity' => 50,
        ])->assertCreated();

        $this->assertDatabaseHas('tuks', ['code' => 'TUK-MAINT-01', 'status' => 'maintenance']);
    }

    public function test_it_downtime_merges_overlaps_and_clips_month_boundaries(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        ItIncident::query()->delete();
        ItService::query()->delete();
        DataQualityRun::query()->delete();

        $service = ItService::create([
            'name' => 'Layanan Uji Reliabilitas',
            'owner' => 'Tim Infrastruktur',
            'target_uptime' => 99.5,
            'status' => 'operational',
        ]);

        ItIncident::create([
            'it_service_id' => $service->id,
            'reference' => 'INC-OVERLAP-1',
            'severity' => 'high',
            'status' => 'resolved',
            'started_at' => '2026-01-10 10:00:00',
            'resolved_at' => '2026-01-10 12:00:00',
            'summary' => 'Overlap interval satu.',
        ]);
        ItIncident::create([
            'it_service_id' => $service->id,
            'reference' => 'INC-OVERLAP-2',
            'severity' => 'medium',
            'status' => 'resolved',
            'started_at' => '2026-01-10 11:00:00',
            'resolved_at' => '2026-01-10 13:00:00',
            'summary' => 'Overlap interval dua.',
        ]);
        ItIncident::create([
            'it_service_id' => $service->id,
            'reference' => 'INC-MONTH-BOUNDARY',
            'severity' => 'critical',
            'status' => 'resolved',
            'started_at' => '2026-01-31 23:00:00',
            'resolved_at' => '2026-02-01 03:00:00',
            'summary' => 'Insiden melintasi batas bulan.',
        ]);

        $january = $this->actingAs($director)->getJson('/api/it?year=2026&month=1')->assertOk();
        $janRow = collect($january->json('monthly'))->firstWhere('period', 'Jan 26');
        $this->assertSame(4.0, (float) $janRow['downtime_hours']);
        $this->assertSame(2.0, (float) $janRow['mttr_hours']);

        $february = $this->actingAs($director)->getJson('/api/it?year=2026&month=2')->assertOk();
        $febRow = collect($february->json('monthly'))->firstWhere('period', 'Feb 26');
        $this->assertSame(3.0, (float) $febRow['downtime_hours']);
        $this->assertSame(4.0, (float) $febRow['mttr_hours']);
    }

    public function test_it_kpis_are_system_derived_from_incident_and_data_quality_ledgers(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        ItIncident::query()->delete();
        ItService::query()->delete();
        DataQualityRun::query()->delete();

        $service = ItService::create([
            'name' => 'Layanan KPI IT',
            'owner' => 'Tim Aplikasi',
            'target_uptime' => 99.5,
            'status' => 'operational',
        ]);
        ItIncident::create([
            'it_service_id' => $service->id,
            'reference' => 'INC-KPI-001',
            'severity' => 'high',
            'status' => 'resolved',
            'started_at' => '2026-01-10 10:00:00',
            'resolved_at' => '2026-01-10 12:00:00',
            'summary' => 'Insiden untuk KPI.',
        ]);
        DataQualityRun::create([
            'reference' => 'DQ-KPI-001',
            'dataset_name' => 'Dataset A',
            'source_system' => 'System A',
            'assessed_at' => '2026-01-20 10:00:00',
            'total_records' => 100,
            'valid_records' => 98,
            'recorded_by' => $director->id,
        ]);
        DataQualityRun::create([
            'reference' => 'DQ-KPI-002',
            'dataset_name' => 'Dataset B',
            'source_system' => 'System B',
            'assessed_at' => '2026-01-21 10:00:00',
            'total_records' => 200,
            'valid_records' => 190,
            'recorded_by' => $director->id,
        ]);

        $dashboard = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=1')->assertOk();
        $byCode = collect($dashboard->json('overview.kpis'))->keyBy('code');

        $this->assertEqualsWithDelta(99.731, (float) $byCode['IT-UPTIME']['actual'], 0.001);
        $this->assertSame(2.0, (float) $byCode['IT-MTTR']['actual']);
        $this->assertSame(96.0, (float) $byCode['IT-DATA']['actual']);
        $this->assertTrue($byCode['IT-UPTIME']['is_system_derived']);
        $this->assertSame('system_derived', $byCode['IT-DATA']['source_type']);
    }

    public function test_system_derived_it_kpi_cannot_be_overwritten_manually_or_by_csv(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $kpi = KpiDefinition::where('code', 'IT-DATA')->firstOrFail();

        $this->actingAs($director)->postJson("/api/kpis/{$kpi->id}/measurements", [
            'period' => '2026-09-01',
            'actual' => 100,
        ])->assertUnprocessable()->assertInvalid(['actual']);

        $file = UploadedFile::fake()->createWithContent(
            'it-kpi.csv',
            "kpi_code,period,actual,notes\nIT-DATA,2026-09-01,100,Tidak boleh override\n",
        );
        $this->actingAs($director)->postJson('/api/data/import', ['file' => $file])
            ->assertOk()->assertJsonPath('imported', 0)->assertJsonPath('rejected', 1);
    }

    public function test_it_incident_lifecycle_and_data_quality_run_are_audited(): void
    {
        $head = User::where('email', 'it@demo.test')->firstOrFail();
        $service = ItService::firstOrFail();

        $created = $this->actingAs($head)->postJson('/api/it/incidents', [
            'it_service_id' => $service->id,
            'reference' => 'INC-LIFE-001',
            'severity' => 'high',
            'started_at' => '2026-09-20T10:00',
            'summary' => 'Gangguan untuk pengujian lifecycle.',
        ])->assertCreated();
        $id = $created->json('incident.id');

        $this->actingAs($head)->patchJson("/api/it/incidents/{$id}/investigate")
            ->assertOk()->assertJsonPath('incident.status', 'investigating');

        $this->actingAs($head)->patchJson("/api/it/incidents/{$id}/resolve", [
            'resolved_at' => '2026-09-20T12:30:00+07:00',
            'resolution_summary' => 'Layanan dipulihkan setelah restart terkontrol.',
            'root_cause' => 'Worker antrean berhenti memproses tugas.',
        ])->assertOk()->assertJsonPath('incident.status', 'resolved');

        $this->actingAs($head)->postJson('/api/it/data-quality-runs', [
            'reference' => 'DQ-LIFE-001',
            'dataset_name' => 'Register Sertifikasi',
            'source_system' => 'Operational Store',
            'assessed_at' => '2026-09-20T15:00:00+07:00',
            'total_records' => 1000,
            'valid_records' => 970,
            'missing_required_records' => 20,
            'duplicate_records' => 5,
            'freshness_failures' => 5,
        ])->assertCreated()->assertJsonPath('quality_score', 97);

        $this->assertDatabaseHas('audit_logs', ['action' => 'investigate', 'entity_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'resolve', 'entity_id' => $id]);
        $this->assertDatabaseHas('data_quality_runs', ['reference' => 'DQ-LIFE-001', 'valid_records' => 970]);
    }

    public function test_data_quality_run_rejects_valid_count_above_total(): void
    {
        $head = User::where('email', 'it@demo.test')->firstOrFail();

        $this->actingAs($head)->postJson('/api/it/data-quality-runs', [
            'reference' => 'DQ-INVALID-001',
            'dataset_name' => 'Dataset Invalid',
            'source_system' => 'Test',
            'assessed_at' => '2026-09-20T15:00:00+07:00',
            'total_records' => 100,
            'valid_records' => 101,
        ])->assertUnprocessable()->assertInvalid(['valid_records']);
    }


    public function test_current_month_derived_kpis_ignore_future_dated_transactions(): void
    {
        CarbonImmutable::setTestNow('2026-09-15 12:00:00');
        try {
            $director = User::where('role', 'director')->firstOrFail();
            $scheme = CertificationScheme::firstOrFail();
            $tuk = Tuk::firstOrFail();

            $before = collect($this->actingAs($director)
                ->getJson('/api/dashboard?year=2026&month=9')
                ->assertOk()
                ->json('overview.kpis'))
                ->keyBy('code');

            CertificationBatch::create([
                'code' => 'ASM-FUTURE-KPI-001',
                'certification_scheme_id' => $scheme->id,
                'tuk_id' => $tuk->id,
                'assessment_date' => '2026-09-20',
                'total_assesi' => 999,
                'passed' => 0,
                'failed' => 0,
                'pending' => 999,
                'certificates_issued' => 0,
                'issued_on_time' => 0,
                'revenue' => 0,
                'status' => 'planned',
            ]);
            FinancialRecord::create([
                'recorded_on' => '2026-09-20',
                'type' => 'revenue',
                'entry_kind' => 'normal',
                'category' => 'Future test',
                'amount' => 999000000,
                'description' => 'Transaksi setelah as-of date.',
                'reference' => 'FUTURE-KPI-001',
                'source_type' => 'manual',
                'recorded_by' => $director->id,
            ]);

            $after = collect($this->actingAs($director)
                ->getJson('/api/dashboard?year=2026&month=9')
                ->assertOk()
                ->json('overview.kpis'))
                ->keyBy('code');

            $this->assertSame((float) $before['CERT-VOLUME']['actual'], (float) $after['CERT-VOLUME']['actual']);
            $this->assertSame((float) $before['FIN-REV']['actual'], (float) $after['FIN-REV']['actual']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_historical_finance_report_keeps_payment_that_was_reversed_after_period_end(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $invoice = FinanceInvoice::create([
            'invoice_number' => 'INV-HIST-REV-001',
            'issued_on' => '2026-08-01',
            'due_on' => '2026-08-20',
            'customer_name' => 'PT Historical Payment',
            'category' => 'Jasa sertifikasi',
            'department_code' => 'finance',
            'amount' => 25000000,
            'description' => 'Invoice untuk snapshot historis.',
            'status' => 'paid',
            'created_by' => $director->id,
        ]);
        FinancePayment::create([
            'finance_invoice_id' => $invoice->id,
            'paid_on' => '2026-08-10',
            'amount' => 25000000,
            'reference' => 'PAY-HIST-REV-001',
            'recorded_by' => $director->id,
            'reversed_at' => '2026-09-01 09:00:00',
            'reversed_by' => $director->id,
            'reversal_reason' => 'Koreksi setelah periode Agustus ditutup.',
        ]);

        $response = $this->actingAs($director)->getJson('/api/finance?year=2026&month=8')->assertOk();
        $row = collect($response->json('invoices'))->firstWhere('invoice_number', 'INV-HIST-REV-001');

        $this->assertSame(25000000.0, (float) $row['paid']);
        $this->assertSame(0.0, (float) $row['outstanding']);
        $this->assertGreaterThanOrEqual(25000000.0, (float) $response->json('summary.payments_received'));
    }

    public function test_it_uptime_denominator_starts_when_service_monitoring_begins(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        ItIncident::query()->delete();
        ItService::query()->delete();
        DataQualityRun::query()->delete();

        $service = ItService::create([
            'name' => 'Layanan Mid-Month',
            'owner' => 'Tim Infrastruktur',
            'target_uptime' => 99.5,
            'monitoring_started_at' => '2026-01-16 00:00:00',
            'status' => 'operational',
        ]);
        ItIncident::create([
            'it_service_id' => $service->id,
            'reference' => 'INC-MID-MONTH-001',
            'severity' => 'high',
            'status' => 'resolved',
            'started_at' => '2026-01-20 00:00:00',
            'resolved_at' => '2026-01-21 00:00:00',
            'summary' => 'Gangguan 24 jam setelah pemantauan dimulai.',
        ]);

        $response = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=1')->assertOk();
        $byCode = collect($response->json('overview.kpis'))->keyBy('code');
        $possibleMinutes = CarbonImmutable::parse('2026-01-16 00:00:00')->diffInMinutes(CarbonImmutable::parse('2026-01-31 23:59:59'));
        $expected = round(100 - (1440 / $possibleMinutes * 100), 3);

        $this->assertEqualsWithDelta($expected, (float) $byCode['IT-UPTIME']['actual'], 0.001);
    }

    public function test_historical_certification_backlog_ignores_issuance_after_report_end(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $scheme = CertificationScheme::firstOrFail();
        $tuk = Tuk::firstOrFail();

        $before = $this->actingAs($director)->getJson('/api/certification?year=2026&month=8')->assertOk();
        $baselineBacklog = (int) $before->json('summary.certificate_backlog');
        $baselineActive = (int) $before->json('summary.active_batches');

        $batch = CertificationBatch::create([
            'code' => 'ASM-HIST-BACKLOG-001',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessment_date' => '2026-08-01',
            'assessment_completed_at' => '2026-08-04 10:00:00',
            'decision_at' => '2026-08-05 10:00:00',
            'certificate_due_at' => '2026-09-04 10:00:00',
            'completed_at' => '2026-09-10 12:00:00',
            'total_assesi' => 10,
            'passed' => 10,
            'failed' => 0,
            'pending' => 0,
            'certificates_issued' => 10,
            'issued_on_time' => 0,
            'revenue' => 0,
            'status' => 'completed',
        ]);
        CertificateIssuance::create([
            'certification_batch_id' => $batch->id,
            'issued_count' => 10,
            'issued_at' => '2026-09-10 09:00:00',
            'reference' => 'CERT-HIST-BACKLOG-001',
            'recorded_by' => $director->id,
        ]);

        $after = $this->actingAs($director)->getJson('/api/certification?year=2026&month=8')->assertOk();
        $this->assertSame($baselineBacklog + 10, (int) $after->json('summary.certificate_backlog'));
        $this->assertSame($baselineActive + 1, (int) $after->json('summary.active_batches'));
    }


    public function test_historical_certification_rows_hide_decision_and_issuance_after_cutoff(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $scheme = CertificationScheme::firstOrFail();
        $tuk = Tuk::firstOrFail();

        $batch = CertificationBatch::create([
            'code' => 'ASM-HIST-DECISION-001',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessment_date' => '2026-08-20',
            'assessment_completed_at' => '2026-08-20 16:00:00',
            'decision_at' => '2026-09-02 10:00:00',
            'certificate_due_at' => '2026-10-02 10:00:00',
            'completed_at' => '2026-09-05 12:00:00',
            'total_assesi' => 8,
            'passed' => 6,
            'failed' => 2,
            'pending' => 0,
            'certificates_issued' => 6,
            'issued_on_time' => 6,
            'revenue' => 0,
            'status' => 'completed',
        ]);
        CertificateIssuance::create([
            'certification_batch_id' => $batch->id,
            'issued_count' => 6,
            'issued_at' => '2026-09-05 09:00:00',
            'reference' => 'CERT-HIST-DECISION-001',
            'recorded_by' => $director->id,
        ]);

        $response = $this->actingAs($director)->getJson('/api/certification?year=2026&month=8')->assertOk();
        $row = collect($response->json('batches'))->firstWhere('code', 'ASM-HIST-DECISION-001');

        $this->assertNotNull($row);
        $this->assertSame('assessment', $row['status']);
        $this->assertSame('assessment', $row['status_as_of']);
        $this->assertSame(0, (int) $row['passed']);
        $this->assertSame(0, (int) $row['failed']);
        $this->assertSame(8, (int) $row['pending']);
        $this->assertNull($row['decision_at']);
        $this->assertNull($row['certificate_due_at']);
        $this->assertNull($row['completed_at']);
        $this->assertSame(0, (int) $row['certificates_issued']);
        $this->assertCount(0, $row['issuances']);
    }

    public function test_historical_certification_results_and_cert_pass_kpi_do_not_rewrite_after_later_correction(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $scheme = CertificationScheme::firstOrFail();
        $tuk = Tuk::firstOrFail();

        $batch = CertificationBatch::create([
            'code' => 'ASM-HIST-CORRECTION-001',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessment_date' => '2026-08-10',
            'assessment_completed_at' => '2026-08-10 15:00:00',
            'decision_at' => '2026-08-11 09:00:00',
            'certificate_due_at' => '2026-09-10 09:00:00',
            'total_assesi' => 10,
            'passed' => 8,
            'failed' => 2,
            'pending' => 0,
            'certificates_issued' => 0,
            'issued_on_time' => 0,
            'revenue' => 0,
            'status' => 'decision',
        ]);

        $beforeCertification = $this->actingAs($director)->getJson('/api/certification?year=2026&month=8')->assertOk();
        $beforeRow = collect($beforeCertification->json('batches'))->firstWhere('code', $batch->code);
        $beforeDashboard = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=8')->assertOk();
        $beforeCertPass = (float) collect($beforeDashboard->json('overview.kpis'))->firstWhere('code', 'CERT-PASS')['actual'];

        $beforeState = $batch->only([
            'status', 'passed', 'failed', 'pending', 'assessment_completed_at', 'decision_at',
            'certificate_due_at', 'completed_at', 'certificates_issued', 'issued_on_time',
        ]);
        $batch->update(['passed' => 7, 'failed' => 3, 'pending' => 0]);
        AuditLog::create([
            'user_id' => $director->id,
            'action' => 'update',
            'entity_type' => CertificationBatch::class,
            'entity_id' => $batch->id,
            'changes' => [
                'before' => $beforeState,
                'after' => $batch->fresh()->only(array_keys($beforeState)),
            ],
            'created_at' => '2026-09-05 10:00:00',
            'updated_at' => '2026-09-05 10:00:00',
        ]);

        $afterCertification = $this->actingAs($director)->getJson('/api/certification?year=2026&month=8')->assertOk();
        $afterRow = collect($afterCertification->json('batches'))->firstWhere('code', $batch->code);
        $afterDashboard = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=8')->assertOk();
        $afterCertPass = (float) collect($afterDashboard->json('overview.kpis'))->firstWhere('code', 'CERT-PASS')['actual'];

        $this->assertSame(8, (int) $beforeRow['passed']);
        $this->assertSame(8, (int) $afterRow['passed']);
        $this->assertSame(2, (int) $afterRow['failed']);
        $this->assertSame($beforeCertPass, $afterCertPass);
    }

    public function test_historical_certification_candidate_is_retained_when_decision_date_is_corrected_out_of_period(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $scheme = CertificationScheme::firstOrFail();
        $tuk = Tuk::firstOrFail();

        $batch = CertificationBatch::create([
            'code' => 'ASM-HIST-DATE-CORRECTION-001',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessment_date' => '2026-08-10',
            'assessment_completed_at' => '2026-08-10 15:00:00',
            'decision_at' => '2026-08-11 09:00:00',
            'certificate_due_at' => '2026-09-10 09:00:00',
            'total_assesi' => 10,
            'passed' => 8,
            'failed' => 2,
            'pending' => 0,
            'certificates_issued' => 0,
            'issued_on_time' => 0,
            'revenue' => 0,
            'status' => 'decision',
        ]);

        $before = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=8')->assertOk();
        $beforeCertPass = (float) collect($before->json('overview.kpis'))->firstWhere('code', 'CERT-PASS')['actual'];

        $beforeState = $batch->only([
            'status', 'passed', 'failed', 'pending', 'assessment_completed_at', 'decision_at',
            'certificate_due_at', 'completed_at', 'certificates_issued', 'issued_on_time',
        ]);
        $batch->update([
            'decision_at' => '2026-09-11 09:00:00',
            'certificate_due_at' => '2026-10-11 09:00:00',
        ]);
        AuditLog::create([
            'user_id' => $director->id,
            'action' => 'update',
            'entity_type' => CertificationBatch::class,
            'entity_id' => $batch->id,
            'changes' => [
                'before' => $beforeState,
                'after' => $batch->fresh()->only(array_keys($beforeState)),
            ],
            'created_at' => '2026-09-12 10:00:00',
            'updated_at' => '2026-09-12 10:00:00',
        ]);

        $after = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=8')->assertOk();
        $afterCertPass = (float) collect($after->json('overview.kpis'))->firstWhere('code', 'CERT-PASS')['actual'];
        $historical = $this->actingAs($director)->getJson('/api/certification?year=2026&month=8')->assertOk();
        $row = collect($historical->json('batches'))->firstWhere('code', $batch->code);

        $this->assertSame($beforeCertPass, $afterCertPass);
        $this->assertSame('2026-08-11', CarbonImmutable::parse($row['decision_at'])->toDateString());
        $this->assertSame(8, (int) $row['passed']);
        $this->assertSame(2, (int) $row['failed']);
    }

    public function test_historical_finance_rows_hide_future_payments_and_future_reversals(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $invoice = FinanceInvoice::create([
            'invoice_number' => 'INV-HIST-PAYMENT-VIS-001',
            'issued_on' => '2026-08-01',
            'due_on' => '2026-08-31',
            'customer_name' => 'Historical Customer',
            'category' => 'Sertifikasi',
            'department_code' => 'finance',
            'amount' => 10000000,
            'description' => 'Historical payment visibility test.',
            'status' => 'paid',
            'created_by' => $director->id,
        ]);
        FinancePayment::create([
            'finance_invoice_id' => $invoice->id,
            'paid_on' => '2026-09-03',
            'amount' => 10000000,
            'reference' => 'PAY-HIST-VIS-001',
            'reversed_at' => '2026-10-01 10:00:00',
            'reversal_reason' => 'Correction after historical cutoff.',
            'recorded_by' => $director->id,
        ]);

        $row = collect($this->actingAs($director)->getJson('/api/finance?year=2026&month=8')->assertOk()->json('invoices'))
            ->firstWhere('invoice_number', 'INV-HIST-PAYMENT-VIS-001');

        $this->assertNotNull($row);
        $this->assertSame(0.0, (float) $row['paid']);
        $this->assertSame(10000000.0, (float) $row['outstanding']);
        $this->assertCount(0, $row['payments']);
    }

    public function test_historical_it_rows_hide_resolution_details_after_cutoff(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $service = ItService::create([
            'name' => 'Historical Resolution Service',
            'owner' => 'Tim Infrastruktur',
            'target_uptime' => 99.5,
            'monitoring_started_at' => '2026-08-01 00:00:00',
            'status' => 'operational',
        ]);
        ItIncident::create([
            'it_service_id' => $service->id,
            'reference' => 'INC-HIST-RESOLUTION-001',
            'severity' => 'high',
            'status' => 'resolved',
            'started_at' => '2026-08-20 09:00:00',
            'acknowledged_at' => '2026-08-20 09:15:00',
            'resolved_at' => '2026-09-02 10:00:00',
            'resolved_by' => $director->id,
            'summary' => 'Gangguan historis.',
            'resolution_summary' => 'Perbaikan dilakukan September.',
            'root_cause' => 'Akar masalah baru diketahui September.',
        ]);

        $row = collect($this->actingAs($director)->getJson('/api/it?year=2026&month=8')->assertOk()->json('incidents'))
            ->firstWhere('reference', 'INC-HIST-RESOLUTION-001');

        $this->assertNotNull($row);
        $this->assertSame('investigating', $row['status']);
        $this->assertSame('investigating', $row['status_as_of']);
        $this->assertNull($row['resolved_at']);
        $this->assertNull($row['resolved_by']);
        $this->assertNull($row['resolution_summary']);
        $this->assertNull($row['root_cause']);
    }

}
