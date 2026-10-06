<?php

namespace Tests\Feature;

use App\Models\ActionItem;
use App\Models\AuditLog;
use App\Models\CorrectiveAction;
use App\Models\ComplianceFinding;
use App\Models\CertificationBatch;
use App\Models\CertificationAppeal;
use App\Models\DataImportBatch;
use App\Models\DataSource;
use App\Models\Department;
use App\Models\FinancialRecord;
use App\Models\ManagementReview;
use App\Models\ManagementReviewItem;
use App\Models\ReportSnapshot;
use App\Models\RiskSignal;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\ReportExportService;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_director_can_generate_immutable_executive_snapshot(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $response = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'executive',
            'year' => 2026,
            'month' => 9,
        ])->assertCreated();

        $id = $response->json('report.id');
        $snapshot = ReportSnapshot::findOrFail($id);
        $hash = $snapshot->content_hash;
        $payload = $snapshot->payload;

        FinancialRecord::create([
            'recorded_on' => '2026-09-30',
            'type' => 'revenue',
            'entry_kind' => 'normal',
            'category' => 'Regression snapshot isolation',
            'department_code' => 'finance',
            'amount' => 999999,
            'description' => 'Operational ledger entry created after the immutable snapshot.',
            'reference' => 'SNAPSHOT-AFTER-'.$snapshot->id,
            'source_type' => 'manual',
            'recorded_by' => $director->id,
        ]);

        $snapshot->refresh();
        $this->assertSame($hash, $snapshot->content_hash);
        $this->assertSame($payload, $snapshot->payload);
        $this->assertSame(64, strlen($snapshot->content_hash));
        $this->assertDatabaseHas('audit_logs', ['action' => 'generate_report_snapshot', 'entity_id' => $id]);
    }


    public function test_finance_report_provenance_manifest_does_not_leak_other_domain_imports(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $creator = User::where('email', 'mutu@demo.test')->firstOrFail();

        $financeSource = DataSource::create([
            'code' => 'SRC-RPT-FIN',
            'name' => 'Finance Import Source',
            'source_type' => 'internal',
            'authority_rank' => 80,
            'owner_name' => 'Finance',
        ]);
        $certSource = DataSource::create([
            'code' => 'SRC-RPT-CERT',
            'name' => 'Certification Import Source',
            'source_type' => 'internal',
            'authority_rank' => 80,
            'owner_name' => 'Certification',
        ]);

        DataImportBatch::create([
            'reference' => 'IMP-RPT-FIN-001',
            'data_source_id' => $financeSource->id,
            'dataset_type' => 'finance_invoices',
            'dataset_name' => 'Finance invoices',
            'file_name' => 'finance-private.csv',
            'sha256' => hash('sha256', 'finance-private'),
            'imported_at' => '2026-09-15 10:00:00',
            'total_rows' => 1,
            'accepted_rows' => 1,
            'rejected_rows' => 0,
            'status' => 'completed',
            'reconciliation_status' => 'reconciled',
            'created_by' => $creator->id,
        ]);
        DataImportBatch::create([
            'reference' => 'IMP-RPT-CERT-001',
            'data_source_id' => $certSource->id,
            'dataset_type' => 'certification_batches',
            'dataset_name' => 'Certification batches',
            'file_name' => 'certification-private.csv',
            'sha256' => hash('sha256', 'certification-private'),
            'imported_at' => '2026-09-16 10:00:00',
            'total_rows' => 1,
            'accepted_rows' => 1,
            'rejected_rows' => 0,
            'status' => 'completed',
            'reconciliation_status' => 'reconciled',
            'created_by' => $creator->id,
        ]);

        $report = $this->actingAs($finance)->postJson('/api/reports', [
            'report_type' => 'finance',
            'year' => 2026,
            'month' => 9,
        ])->assertCreated()->json('report');

        $references = collect(data_get($report, 'source_manifest.import_batches', []))->pluck('reference');
        $sourceCodes = collect(data_get($report, 'source_manifest.data_sources', []))->pluck('code');

        $this->assertTrue($references->contains('IMP-RPT-FIN-001'));
        $this->assertFalse($references->contains('IMP-RPT-CERT-001'));
        $this->assertTrue($sourceCodes->contains('SRC-RPT-FIN'));
        $this->assertFalse($sourceCodes->contains('SRC-RPT-CERT'));
        $this->assertSame(['finance_invoices', 'finance_payments', 'financial_records'], data_get($report, 'source_manifest.scope.dataset_types'));
    }

    public function test_department_head_cannot_generate_report_for_other_domain(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();

        $this->actingAs($finance)->postJson('/api/reports', [
            'report_type' => 'certification',
            'year' => 2026,
            'month' => 9,
        ])->assertUnprocessable()->assertJsonValidationErrors(['report_type']);

        $this->actingAs($finance)->postJson('/api/reports', [
            'report_type' => 'finance',
            'year' => 2026,
            'month' => 9,
        ])->assertCreated()->assertJsonPath('report.report_type', 'finance');
    }

    public function test_non_director_department_report_is_forced_to_own_department(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $response = $this->actingAs($finance)->postJson('/api/reports', [
            'report_type' => 'department',
            'department_code' => 'it',
            'year' => 2026,
            'month' => 9,
        ])->assertCreated();

        $this->assertSame('finance', $response->json('report.payload.meta.department.code'));
    }

    public function test_viewer_can_view_reports_but_cannot_export_snapshot(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $finance->update(['role' => 'viewer']);

        $snapshotId = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'finance', 'year' => 2026, 'month' => 9,
        ])->assertCreated()->json('report.id');

        $this->actingAs($finance->fresh())->getJson('/api/reports')->assertOk();
        $this->actingAs($finance->fresh())->get("/api/reports/{$snapshotId}/export/json")->assertForbidden();
    }


    public function test_revoked_domain_permission_blocks_existing_snapshot_and_removes_it_from_history(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();

        $snapshotId = $this->actingAs($finance)->postJson('/api/reports', [
            'report_type' => 'finance', 'year' => 2026, 'month' => 9,
        ])->assertCreated()->json('report.id');

        UserPermission::query()->create([
            'user_id' => $finance->id,
            'permission' => 'finance.view',
            'allowed' => false,
            'reason' => 'Regression test permission revocation.',
            'granted_by' => $director->id,
        ]);

        $finance = $finance->fresh();
        $this->actingAs($finance)->getJson("/api/reports/{$snapshotId}")->assertForbidden();

        $history = $this->actingAs($finance)->getJson('/api/reports')->assertOk()->json('history');
        $this->assertFalse(collect($history)->pluck('id')->contains($snapshotId));
    }

    public function test_evidence_pack_is_a_standard_zip_with_manifest_and_hash(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $id = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'executive', 'year' => 2026, 'month' => 9,
        ])->assertCreated()->json('report.id');
        $snapshot = ReportSnapshot::findOrFail($id);

        $zip = app(ReportExportService::class)->evidencePack($snapshot);
        $this->assertStringStartsWith("PK\x03\x04", $zip);
        $this->assertStringContainsString('manifest.json', $zip);
        $this->assertStringContainsString($snapshot->content_hash, $zip);
    }

    public function test_evidence_pack_is_reproducible_and_manifest_hashes_export_files(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $id = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'executive', 'year' => 2026, 'month' => 9,
        ])->assertCreated()->json('report.id');
        $snapshot = ReportSnapshot::findOrFail($id);
        $exporter = app(ReportExportService::class);

        $first = $exporter->evidencePack($snapshot);
        $second = $exporter->evidencePack($snapshot->fresh());

        $this->assertSame(hash('sha256', $first), hash('sha256', $second));
        $this->assertSame($first, $second);
        $this->assertStringContainsString(hash('sha256', $exporter->json($snapshot)), $first);
        $this->assertStringContainsString(hash('sha256', $exporter->csv($snapshot)), $first);
        $this->assertStringContainsString('verification', $first);
    }

    public function test_cross_department_user_cannot_open_company_wide_or_other_department_risk_snapshot(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $quality = User::where('email', 'mutu@demo.test')->firstOrFail();

        $directorSnapshot = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'risk_action', 'year' => 2026, 'month' => 9,
        ])->assertCreated()->json('report.id');
        $this->actingAs($finance)->getJson("/api/reports/{$directorSnapshot}")->assertForbidden();

        $qualitySnapshot = $this->actingAs($quality)->postJson('/api/reports', [
            'report_type' => 'risk_action', 'year' => 2026, 'month' => 9,
        ])->assertCreated()->json('report.id');
        $this->actingAs($finance)->getJson("/api/reports/{$qualitySnapshot}")->assertForbidden();
        $this->actingAs($quality)->getJson("/api/reports/{$qualitySnapshot}")->assertOk();
    }

    public function test_risk_action_report_uses_status_as_of_report_end(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $finance = Department::where('code', 'finance')->firstOrFail();
        $signal = RiskSignal::create([
            'fingerprint' => 'test:historical-status:1',
            'rule_code' => 'TEST_HISTORY',
            'category' => 'finance',
            'severity' => 'high',
            'status' => 'resolved',
            'department_id' => $finance->id,
            'source_type' => 'test',
            'source_id' => 1,
            'source_reference' => 'HIST-001',
            'title' => 'Historical risk status',
            'description' => 'Risk masih terbuka pada akhir Agustus.',
            'detected_at' => '2026-08-10 09:00:00',
            'due_at' => '2026-08-20 23:59:59',
            'last_observed_at' => '2026-09-02 09:00:00',
            'resolved_at' => '2026-09-02 09:00:00',
            'resolved_by' => $director->id,
            'resolution_note' => 'Diselesaikan setelah periode laporan.',
            'created_at' => '2026-08-10 09:00:00',
            'updated_at' => '2026-09-02 09:00:00',
        ]);
        $action = ActionItem::create([
            'department_id' => $finance->id,
            'risk_signal_id' => $signal->id,
            'title' => 'Historical action status',
            'description' => 'Action belum selesai pada akhir Agustus.',
            'priority' => 'high',
            'status' => 'completed',
            'owner_name' => 'Kepala Keuangan',
            'due_date' => '2026-08-25',
            'completed_at' => '2026-09-03 10:00:00',
            'resolution_note' => 'Selesai setelah periode laporan.',
            'resolution_evidence' => 'EVID-HIST-001',
            'created_by' => $director->id,
            'updated_by' => $director->id,
            'created_at' => '2026-08-11 09:00:00',
            'updated_at' => '2026-09-03 10:00:00',
        ]);

        $id = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'risk_action', 'year' => 2026, 'month' => 8,
        ])->assertCreated()->json('report.id');
        $snapshot = ReportSnapshot::findOrFail($id);
        $signalRow = collect($snapshot->payload['content']['signals'])->firstWhere('id', $signal->id);
        $actionRow = collect($snapshot->payload['content']['actions'])->firstWhere('id', $action->id);

        $this->assertSame('open', $signalRow['status']);
        $this->assertSame('open', $signalRow['status_as_of']);
        $this->assertNull($signalRow['resolved_at_as_of']);
        $this->assertNull($signalRow['resolved_by']);
        $this->assertNull($signalRow['resolution_note']);
        $this->assertSame('open', $actionRow['status']);
        $this->assertSame('open', $actionRow['status_as_of']);
        $this->assertNull($actionRow['completed_at_as_of']);
        $this->assertNull($actionRow['resolution_note']);
        $this->assertNull($actionRow['resolution_evidence']);
    }

    public function test_report_snapshot_model_rejects_update_and_delete(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $id = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'executive', 'year' => 2026, 'month' => 9,
        ])->assertCreated()->json('report.id');

        $snapshot = ReportSnapshot::findOrFail($id);

        try {
            $snapshot->update(['title' => 'Mutated title']);
            $this->fail('Report snapshot update should be rejected.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $snapshot = ReportSnapshot::findOrFail($id);
        try {
            $snapshot->delete();
            $this->fail('Report snapshot delete should be rejected.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertDatabaseHas('report_snapshots', ['id' => $id]);
    }


    public function test_governance_report_excludes_future_capa_and_redacts_future_closure_and_appeal_decision(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $batch = CertificationBatch::firstOrFail();

        $finding = ComplianceFinding::create([
            'reference' => 'FND-HIST-EVID-001',
            'source' => 'Audit internal',
            'category' => 'Dokumentasi',
            'severity' => 'high',
            'title' => 'Historical finding evidence',
            'description' => 'Temuan masih terbuka pada akhir Agustus.',
            'status' => 'closed',
            'owner_name' => 'Pemilik Proses',
            'opened_at' => '2026-08-10 09:00:00',
            'due_at' => '2026-08-25 17:00:00',
            'closed_at' => '2026-09-03 10:00:00',
            'closure_evidence' => 'Evidence baru tersedia September.',
            'created_by' => $director->id,
            'created_at' => '2026-08-10 09:00:00',
            'updated_at' => '2026-09-03 10:00:00',
        ]);
        $futureCapa = CorrectiveAction::create([
            'compliance_finding_id' => $finding->id,
            'title' => 'Future CAPA',
            'description' => 'CAPA baru dibuat setelah cutoff.',
            'owner_name' => 'Pemilik Proses',
            'due_date' => '2026-09-20',
            'status' => 'completed',
            'evidence' => 'Evidence September.',
            'completed_at' => '2026-09-10 10:00:00',
            'created_by' => $director->id,
            'created_at' => '2026-09-04 09:00:00',
            'updated_at' => '2026-09-10 10:00:00',
        ]);
        $appeal = CertificationAppeal::create([
            'certification_batch_id' => $batch->id,
            'reference' => 'APL-HIST-EVID-001',
            'appellant_reference' => 'ASESI-HIST-001',
            'received_at' => '2026-08-15 10:00:00',
            'due_at' => '2026-09-15 10:00:00',
            'reason' => 'Historical appeal.',
            'status' => 'decided',
            'owner_name' => 'Tim Sertifikasi',
            'decision' => 'changed',
            'resolution_summary' => 'Keputusan baru tersedia September.',
            'decision_at' => '2026-09-05 11:00:00',
            'created_by' => $director->id,
            'created_at' => '2026-08-15 10:00:00',
            'updated_at' => '2026-09-05 11:00:00',
        ]);

        $id = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'governance', 'year' => 2026, 'month' => 8,
        ])->assertCreated()->json('report.id');
        $content = ReportSnapshot::findOrFail($id)->payload['content']['governance'];
        $findingRow = collect($content['findings'])->firstWhere('id', $finding->id);
        $appealRow = collect($content['appeals'])->firstWhere('id', $appeal->id);

        $this->assertSame('open', $findingRow['status']);
        $this->assertNull($findingRow['closed_at']);
        $this->assertNull($findingRow['closure_evidence']);
        $this->assertFalse(collect($content['corrective_actions'])->pluck('id')->contains($futureCapa->id));
        $this->assertSame('received', $appealRow['status']);
        $this->assertNull($appealRow['decision']);
        $this->assertNull($appealRow['resolution_summary']);
        $this->assertNull($appealRow['decision_at']);
    }

    public function test_risk_action_report_rolls_back_management_review_and_item_fields_after_cutoff(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $finance = Department::where('code', 'finance')->firstOrFail();
        $signal = RiskSignal::create([
            'fingerprint' => 'test:review-history:1',
            'rule_code' => 'TEST_REVIEW_HISTORY',
            'category' => 'finance',
            'severity' => 'high',
            'status' => 'open',
            'department_id' => $finance->id,
            'source_type' => 'test',
            'source_id' => 991,
            'source_reference' => 'MR-HIST-SIGNAL',
            'title' => 'Management review historical signal',
            'description' => 'Signal untuk regression management review as-of.',
            'detected_at' => '2026-08-10 09:00:00',
            'last_observed_at' => '2026-08-31 09:00:00',
            'created_at' => '2026-08-10 09:00:00',
            'updated_at' => '2026-08-31 09:00:00',
        ]);
        $review = ManagementReview::create([
            'reference' => 'MR-HIST-202608',
            'title' => 'Historical management review',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'meeting_at' => '2026-09-05 10:00:00',
            'chair_name' => 'Director',
            'summary' => 'Ringkasan setelah cutoff',
            'decisions' => 'Keputusan September',
            'status' => 'closed',
            'approved_at' => '2026-09-05 11:00:00',
            'approved_by' => $director->id,
            'closed_at' => '2026-09-06 12:00:00',
            'created_by' => $director->id,
            'created_at' => '2026-08-20 09:00:00',
            'updated_at' => '2026-09-06 12:00:00',
        ]);
        $item = ManagementReviewItem::create([
            'management_review_id' => $review->id,
            'risk_signal_id' => $signal->id,
            'title' => 'Historical review item',
            'owner_name' => 'Owner September',
            'due_date' => '2026-09-10',
            'decision' => 'Decision after cutoff',
            'status' => 'completed',
            'created_at' => '2026-08-20 09:00:00',
            'updated_at' => '2026-09-05 11:30:00',
        ]);
        AuditLog::create([
            'user_id' => $director->id,
            'action' => 'update_management_review',
            'entity_type' => ManagementReview::class,
            'entity_id' => $review->id,
            'changes' => [
                'before' => ['status' => 'draft', 'meeting_at' => null, 'summary' => 'Ringkasan Agustus', 'decisions' => null],
                'after' => ['status' => 'closed', 'meeting_at' => '2026-09-05 10:00:00', 'summary' => 'Ringkasan setelah cutoff', 'decisions' => 'Keputusan September'],
            ],
            'created_at' => '2026-09-05 11:00:00',
            'updated_at' => '2026-09-05 11:00:00',
        ]);
        AuditLog::create([
            'user_id' => $director->id,
            'action' => 'update_management_review_item',
            'entity_type' => ManagementReviewItem::class,
            'entity_id' => $item->id,
            'changes' => [
                'before' => ['decision' => null, 'owner_name' => null, 'due_date' => null, 'status' => 'open'],
                'after' => ['decision' => 'Decision after cutoff', 'owner_name' => 'Owner September', 'due_date' => '2026-09-10', 'status' => 'completed'],
            ],
            'created_at' => '2026-09-05 11:30:00',
            'updated_at' => '2026-09-05 11:30:00',
        ]);

        $id = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'risk_action', 'year' => 2026, 'month' => 8,
        ])->assertCreated()->json('report.id');
        $snapshot = ReportSnapshot::findOrFail($id);
        $row = collect($snapshot->payload['content']['management_reviews'])->firstWhere('id', $review->id);
        $itemRow = collect($row['items'])->firstWhere('id', $item->id);

        $this->assertSame('draft', $row['status']);
        $this->assertNull($row['meeting_at']);
        $this->assertSame('Ringkasan Agustus', $row['summary']);
        $this->assertNull($row['decisions']);
        $this->assertNull($row['approved_at']);
        $this->assertNull($row['approved_by']);
        $this->assertNull($row['closed_at']);
        $this->assertSame('open', $itemRow['status']);
        $this->assertNull($itemRow['decision']);
        $this->assertNull($itemRow['owner_name']);
        $this->assertNull($itemRow['due_date']);
    }


}
