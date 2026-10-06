<?php

namespace Tests\Feature;

use App\Models\CertificationBatch;
use App\Models\ComplianceFinding;
use App\Models\CorrectiveAction;
use App\Models\DataImportBatch;
use App\Models\User;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class GovernanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_all_department_heads_can_read_governance_but_only_admin_can_create_data_source(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $quality = User::where('email', 'mutu@demo.test')->firstOrFail();

        $this->actingAs($finance)->getJson('/api/governance')->assertOk();

        $this->actingAs($finance)->postJson('/api/governance/data-sources', [
            'code' => 'SRC-DENIED', 'name' => 'Denied', 'source_type' => 'internal', 'authority_rank' => 80,
        ])->assertForbidden();

        $this->actingAs($quality)->postJson('/api/governance/data-sources', [
            'code' => 'SRC-ERP', 'name' => 'ERP Internal', 'source_type' => 'internal', 'authority_rank' => 100,
            'owner_name' => 'Finance', 'location' => 'ERP',
        ])->assertCreated();

        $this->assertDatabaseHas('data_sources', ['code' => 'SRC-ERP', 'authority_rank' => 100]);
    }

    public function test_finding_requires_completed_capa_and_evidence_before_closure(): void
    {
        $quality = User::where('email', 'mutu@demo.test')->firstOrFail();

        $findingId = $this->actingAs($quality)->postJson('/api/governance/findings', [
            'reference' => 'FND-TEST-001',
            'source' => 'Audit internal',
            'category' => 'Dokumentasi',
            'severity' => 'high',
            'title' => 'Dokumen belum lengkap',
            'description' => 'Evidence verifikasi belum tersedia.',
            'owner_name' => 'Pemilik Proses',
            'opened_at' => now()->toIso8601String(),
            'due_at' => now()->addDays(10)->toIso8601String(),
        ])->assertCreated()->json('finding.id');

        $actionId = $this->actingAs($quality)->postJson("/api/governance/findings/{$findingId}/actions", [
            'title' => 'Lengkapi evidence',
            'description' => 'Unggah dan validasi dokumen.',
            'owner_name' => 'Pemilik Proses',
            'due_date' => today()->addDays(5)->toDateString(),
        ])->assertCreated()->json('action.id');

        $this->actingAs($quality)->patchJson("/api/governance/findings/{$findingId}", [
            'status' => 'closed',
            'closure_evidence' => 'Dokumen diperiksa.',
        ])->assertUnprocessable()->assertInvalid(['status']);

        $this->actingAs($quality)->patchJson("/api/governance/actions/{$actionId}", [
            'status' => 'completed',
            'evidence' => 'Evidence DOC-001 diverifikasi.',
        ])->assertOk();

        $this->actingAs($quality)->patchJson("/api/governance/findings/{$findingId}", [
            'status' => 'closed',
            'closure_evidence' => 'DOC-001 tervalidasi dan CAPA efektif.',
        ])->assertOk()->assertJsonPath('finding.status', 'closed');

        $this->assertNotNull(ComplianceFinding::findOrFail($findingId)->closed_at);
        $this->assertNotNull(CorrectiveAction::findOrFail($actionId)->completed_at);

        $this->actingAs($quality)->patchJson("/api/governance/actions/{$actionId}", [
            'status' => 'in_progress',
            'evidence' => 'Tidak boleh dibuka kembali.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->actingAs($quality)->patchJson("/api/governance/findings/{$findingId}", [
            'status' => 'in_progress',
            'closure_evidence' => 'Tidak boleh dibuka kembali.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_certification_can_register_and_decide_appeal_but_finance_cannot(): void
    {
        $certification = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $batch = CertificationBatch::whereIn('status', ['decision', 'completed'])->firstOrFail();

        $payload = [
            'certification_batch_id' => $batch->id,
            'reference' => 'APL-TEST-001',
            'appellant_reference' => 'ASESI-TEST-001',
            'received_at' => now()->toIso8601String(),
            'due_at' => now()->addDays(14)->toIso8601String(),
            'reason' => 'Meminta peninjauan keputusan.',
            'owner_name' => 'Tim Sertifikasi',
        ];

        $this->actingAs($finance)->postJson('/api/governance/appeals', $payload)->assertForbidden();
        $appealId = $this->actingAs($certification)->postJson('/api/governance/appeals', $payload)
            ->assertCreated()->json('appeal.id');

        $this->actingAs($certification)->patchJson("/api/governance/appeals/{$appealId}", [
            'status' => 'decided',
            'decision' => 'reassessment',
            'resolution_summary' => 'Dijadwalkan asesmen ulang pada unit yang disengketakan.',
            'decision_at' => now()->addDay()->toIso8601String(),
        ])->assertOk()->assertJsonPath('appeal.decision', 'reassessment');

        $this->actingAs($certification)->patchJson("/api/governance/appeals/{$appealId}", [
            'status' => 'reviewing',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->actingAs($certification)->patchJson("/api/governance/appeals/{$appealId}", [
            'status' => 'closed',
        ])->assertOk()->assertJsonPath('appeal.status', 'closed');
    }

    public function test_csv_import_creates_provenance_batch_with_checksum_and_exception_status(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $file = UploadedFile::fake()->createWithContent(
            'provenance.csv',
            "kpi_code,period,actual,notes\nUNKNOWN-KPI,2026-09-01,95,Tidak valid\n",
        );

        $response = $this->actingAs($director)->postJson('/api/data/import', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('rejected', 1)
            ->assertJsonPath('import_batch.reconciliation_status', 'exception');

        $batch = DataImportBatch::findOrFail($response->json('import_batch.id'));
        $this->assertSame(64, strlen($batch->sha256));
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(1, $batch->rejected_rows);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'import',
            'entity_type' => 'App\\Models\\DataImportBatch',
            'entity_id' => $batch->id,
        ]);
    }

    public function test_governance_expiry_watchlist_includes_master_data_expiring_within_90_days(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $response = $this->actingAs($director)->getJson('/api/governance')->assertOk();

        $this->assertGreaterThan(0, count($response->json('expiry_risks')));
        $this->assertGreaterThan(0, $response->json('summary.expiry_risks_90d'));
    }
}
