<?php

namespace Tests\Feature;

use App\Models\CertificationBatch;
use App\Models\CertificationScheme;
use App\Models\DataSource;
use App\Models\FinanceInvoice;
use App\Models\IntegrationRecordLink;
use App\Models\ItIncident;
use App\Models\ItService;
use App\Models\Tuk;
use App\Models\User;
use App\Models\UserPermission;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class IntegrationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_certification_head_can_stage_publish_and_trace_real_data_rows(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-BNSP-REF')->firstOrFail();
        $file = UploadedFile::fake()->createWithContent(
            'tuk-real.csv',
            "code,name,city,monthly_capacity,status,verification_valid_until,evidence_reference\nTUK-EXT-001,TUK External,Jakarta,180,active,2027-12-31,DOC-TUK-EXT-001\n",
        );

        $stage = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => $file,
            'data_source_id' => $source->id,
            'dataset_type' => 'tuks',
            'dataset_name' => 'Register TUK External',
        ])->assertCreated()
            ->assertJsonPath('batch.status', 'validated')
            ->assertJsonPath('batch.accepted_rows', 1)
            ->assertJsonPath('batch.rejected_rows', 0);

        $batchId = $stage->json('batch.id');
        $this->actingAs($head)->postJson("/api/integrations/batches/{$batchId}/publish")
            ->assertOk()
            ->assertJsonPath('batch.status', 'published')
            ->assertJsonPath('batch.inserted_rows', 1);

        $tuk = Tuk::where('code', 'TUK-EXT-001')->firstOrFail();
        $this->assertDatabaseHas('integration_record_links', [
            'data_source_id' => $source->id,
            'dataset_type' => 'tuks',
            'external_key' => 'TUK-EXT-001',
            'entity_type' => Tuk::class,
            'entity_id' => $tuk->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'publish_integration']);
    }

    public function test_identical_file_cannot_be_staged_twice_for_same_source_and_dataset(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $content = "code,name,city,monthly_capacity,status,verification_valid_until,evidence_reference\nTUK-FILE-IDEM-001,TUK File Idempotent,Jakarta,100,active,2027-12-31,DOC-FILE-1\n";

        $first = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('same-1.csv', $content),
            'data_source_id' => $source->id,
            'dataset_type' => 'tuks',
        ])->assertCreated();

        $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('same-2.csv', $content),
            'data_source_id' => $source->id,
            'dataset_type' => 'tuks',
        ])->assertSessionHasErrors('file');

        $this->assertSame(1, \App\Models\DataImportBatch::query()
            ->where('data_source_id', $source->id)
            ->where('dataset_type', 'tuks')
            ->where('sha256', hash('sha256', $content))
            ->count());
        $this->assertNotNull($first->json('batch.id'));
    }

    public function test_custom_source_headers_can_be_mapped_and_revalidated_before_publish(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $file = UploadedFile::fake()->createWithContent(
            'tuk-custom-header.csv',
            "kode,nama,lokasi,kapasitas,kondisi,berlaku,bukti\nTUK-MAP-001,TUK Mapping,Bandung,120,active,2027-06-30,VER-MAP-001\n",
        );

        $batchId = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => $file,
            'data_source_id' => $source->id,
            'dataset_type' => 'tuks',
        ])->assertCreated()
            ->assertJsonPath('batch.status', 'staged_with_errors')
            ->assertJsonPath('batch.rejected_rows', 1)
            ->json('batch.id');

        $mapping = [
            'code' => 'kode', 'name' => 'nama', 'city' => 'lokasi', 'monthly_capacity' => 'kapasitas',
            'status' => 'kondisi', 'verification_valid_until' => 'berlaku', 'evidence_reference' => 'bukti',
        ];
        $this->actingAs($head)->patchJson("/api/integrations/batches/{$batchId}/mapping", [
            'column_mapping' => $mapping,
            'defaults' => [],
        ])->assertOk()
            ->assertJsonPath('batch.status', 'validated')
            ->assertJsonPath('batch.rejected_rows', 0);

        $this->actingAs($head)->postJson("/api/integrations/batches/{$batchId}/publish")
            ->assertOk()->assertJsonPath('batch.status', 'published');

        $this->assertDatabaseHas('tuks', ['code' => 'TUK-MAP-001', 'city' => 'Bandung']);
    }

    public function test_second_file_with_same_row_hash_skips_existing_record_and_only_inserts_delta(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $header = "code,name,city,monthly_capacity,status,verification_valid_until,evidence_reference\n";
        $row1 = "TUK-IDEM-001,TUK Idempotent,Jakarta,100,active,2027-12-31,DOC-1\n";

        $first = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('idem-1.csv', $header.$row1),
            'data_source_id' => $source->id,
            'dataset_type' => 'tuks',
        ])->assertCreated();
        $this->actingAs($head)->postJson('/api/integrations/batches/'.$first->json('batch.id').'/publish')->assertOk();

        $second = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('idem-2.csv', $header.$row1."TUK-IDEM-002,TUK Delta,Surabaya,110,active,2027-12-31,DOC-2\n"),
            'data_source_id' => $source->id,
            'dataset_type' => 'tuks',
        ])->assertCreated()
            ->assertJsonPath('batch.duplicate_rows', 1)
            ->assertJsonPath('batch.validation_summary.planned_skips', 1)
            ->assertJsonPath('batch.validation_summary.planned_inserts', 1);

        $this->actingAs($head)->postJson('/api/integrations/batches/'.$second->json('batch.id').'/publish')
            ->assertOk()
            ->assertJsonPath('batch.skipped_rows', 1)
            ->assertJsonPath('batch.inserted_rows', 1);

        $this->assertSame(2, Tuk::whereIn('code', ['TUK-IDEM-001', 'TUK-IDEM-002'])->count());
    }

    public function test_changed_immutable_finance_record_is_rejected_in_staging_instead_of_overwritten(): void
    {
        $head = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $header = "invoice_number,issued_on,due_on,customer_name,category,department_code,amount,description\n";

        $first = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('invoice-1.csv', $header."INV-EXT-001,2026-09-01,2026-09-30,PT External,Jasa sertifikasi,finance,50000000,Invoice real\n"),
            'data_source_id' => $source->id,
            'dataset_type' => 'finance_invoices',
        ])->assertCreated();
        $this->actingAs($head)->postJson('/api/integrations/batches/'.$first->json('batch.id').'/publish')->assertOk();

        $this->assertDatabaseHas('finance_invoices', ['invoice_number' => 'INV-EXT-001', 'amount' => 50000000]);

        $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('invoice-2.csv', $header."INV-EXT-001,2026-09-01,2026-09-30,PT External,Jasa sertifikasi,finance,65000000,Invoice berubah\n"),
            'data_source_id' => $source->id,
            'dataset_type' => 'finance_invoices',
        ])->assertCreated()
            ->assertJsonPath('batch.status', 'staged_with_errors')
            ->assertJsonPath('batch.rejected_rows', 1)
            ->assertJsonPath('batch.preview_rows.0.status', 'invalid');

        $this->assertSame(50000000.0, (float) FinanceInvoice::where('invoice_number', 'INV-EXT-001')->value('amount'));
        $this->assertSame(1, IntegrationRecordLink::where('dataset_type', 'finance_invoices')->where('external_key', 'INV-EXT-001')->count());
    }


    public function test_lower_authority_source_cannot_overwrite_entity_owned_by_higher_authority_source(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $high = DataSource::where('code', 'SRC-BNSP-REF')->firstOrFail();
        $low = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $header = "code,name,city,monthly_capacity,status,verification_valid_until,evidence_reference\n";

        $first = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('high.csv', $header."TUK-AUTH-001,TUK Authority,Jakarta,100,active,2027-12-31,HIGH-DOC\n"),
            'data_source_id' => $high->id,
            'dataset_type' => 'tuks',
        ])->assertCreated();
        $this->actingAs($head)->postJson('/api/integrations/batches/'.$first->json('batch.id').'/publish')->assertOk();

        $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('low.csv', $header."TUK-AUTH-001,TUK Authority Diubah,Jakarta,999,active,2027-12-31,LOW-DOC\n"),
            'data_source_id' => $low->id,
            'dataset_type' => 'tuks',
        ])->assertCreated()
            ->assertJsonPath('batch.status', 'staged_with_errors')
            ->assertJsonPath('batch.rejected_rows', 1);

        $this->assertSame(100, (int) Tuk::where('code', 'TUK-AUTH-001')->value('monthly_capacity'));
    }


    public function test_publish_rechecks_authority_after_staging_before_mutating_entity(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $high = DataSource::where('code', 'SRC-BNSP-REF')->firstOrFail();
        $low = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $tuk = Tuk::query()->create([
            'code' => 'TUK-AUTH-RACE-001',
            'name' => 'TUK Authority Race',
            'city' => 'Jakarta',
            'monthly_capacity' => 100,
            'status' => 'active',
            'verification_valid_until' => '2027-12-31',
            'evidence_reference' => 'BASE-DOC',
        ]);
        $header = "code,name,city,monthly_capacity,status,verification_valid_until,evidence_reference\n";

        // At staging time no provenance owner exists, so the low-authority batch is valid.
        $batchId = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent(
                'low-before-high-link.csv',
                $header."TUK-AUTH-RACE-001,TUK Low Update,Jakarta,999,active,2027-12-31,LOW-DOC\n",
            ),
            'data_source_id' => $low->id,
            'dataset_type' => 'tuks',
        ])->assertCreated()
            ->assertJsonPath('batch.status', 'validated')
            ->json('batch.id');

        // A higher-authority source takes ownership after staging but before publish.
        IntegrationRecordLink::query()->create([
            'data_source_id' => $high->id,
            'dataset_type' => 'tuks',
            'external_key' => $tuk->code,
            'entity_type' => Tuk::class,
            'entity_id' => $tuk->id,
            'row_hash' => str_repeat('a', 64),
            'last_import_batch_id' => null,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($head)->postJson("/api/integrations/batches/{$batchId}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['batch']);

        $this->assertSame(100, (int) $tuk->fresh()->monthly_capacity);
        $this->assertSame('BASE-DOC', $tuk->fresh()->evidence_reference);
    }

    public function test_read_only_integration_permission_can_view_own_domain_batches_but_cannot_mutate(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $header = "invoice_number,issued_on,due_on,customer_name,category,department_code,amount,description\n";

        $batchId = $this->actingAs($finance)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent(
                'readonly-finance.csv',
                $header."INV-READ-001,2026-09-01,2026-09-30,PT Read Only,Jasa sertifikasi,finance,12000000,Read-only batch\n",
            ),
            'data_source_id' => $source->id,
            'dataset_type' => 'finance_invoices',
        ])->assertCreated()->json('batch.id');

        UserPermission::query()->create([
            'user_id' => $finance->id,
            'permission' => 'integrations.manage',
            'allowed' => false,
            'reason' => 'Regression test read-only integration access.',
            'granted_by' => $director->id,
        ]);

        $finance = $finance->fresh();
        $index = $this->actingAs($finance)->getJson('/api/integrations')->assertOk();
        $this->assertTrue(collect($index->json('datasets'))->pluck('type')->contains('finance_invoices'));
        $this->assertTrue(collect($index->json('batches'))->pluck('id')->contains($batchId));

        $this->actingAs($finance)->getJson("/api/integrations/batches/{$batchId}")
            ->assertOk()->assertJsonPath('batch.id', $batchId);
        $this->actingAs($finance)->postJson("/api/integrations/batches/{$batchId}/publish")
            ->assertForbidden();
    }


    public function test_integration_cannot_regress_existing_certification_lifecycle(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $scheme = CertificationScheme::query()->where('is_active', true)->firstOrFail();
        $tuk = Tuk::query()->where('status', 'active')->firstOrFail();
        $batch = CertificationBatch::query()->create([
            'code' => 'BAT-INT-LIFE-001',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessment_date' => '2026-09-15',
            'total_assesi' => 2,
            'passed' => 2,
            'failed' => 0,
            'pending' => 0,
            'certificates_issued' => 0,
            'issued_on_time' => 0,
            'revenue' => 0,
            'status' => 'decision',
            'assessment_completed_at' => '2026-09-15 16:00:00',
            'decision_at' => '2026-09-17 10:00:00',
            'certificate_due_at' => '2026-10-17 10:00:00',
        ]);

        $header = "code,scheme_code,tuk_code,assessor_registration_no,assessment_date,total_assesi,passed,failed,pending,revenue,status,assessment_completed_at,decision_at,certificate_due_at,completed_at
";
        $row = "{$batch->code},{$scheme->code},{$tuk->code},,2026-09-15,2,2,0,0,0,assessment,2026-09-15 16:00:00,,,
";
        $batchId = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('cert-regression.csv', $header.$row),
            'data_source_id' => $source->id,
            'dataset_type' => 'certification_batches',
        ])->assertCreated()->assertJsonPath('batch.status', 'validated')->json('batch.id');

        $this->actingAs($head)->postJson("/api/integrations/batches/{$batchId}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertSame('decision', $batch->fresh()->status);
    }

    public function test_integration_uses_system_derived_certificate_due_date(): void
    {
        $head = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $scheme = CertificationScheme::query()->where('is_active', true)->firstOrFail();
        $tuk = Tuk::query()->where('status', 'active')->firstOrFail();
        $batch = CertificationBatch::query()->create([
            'code' => 'BAT-INT-DUE-001',
            'certification_scheme_id' => $scheme->id,
            'tuk_id' => $tuk->id,
            'assessment_date' => '2026-09-15',
            'total_assesi' => 1,
            'passed' => 1,
            'failed' => 0,
            'pending' => 0,
            'certificates_issued' => 0,
            'issued_on_time' => 0,
            'revenue' => 0,
            'status' => 'assessment',
            'assessment_completed_at' => '2026-09-15 16:00:00',
        ]);

        $header = "code,scheme_code,tuk_code,assessor_registration_no,assessment_date,total_assesi,passed,failed,pending,revenue,status,assessment_completed_at,decision_at,certificate_due_at,completed_at
";
        $row = "{$batch->code},{$scheme->code},{$tuk->code},,2026-09-15,1,1,0,0,0,decision,2026-09-15 16:00:00,2026-09-17 10:00:00,2099-12-31 23:59:59,
";
        $batchId = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('cert-due.csv', $header.$row),
            'data_source_id' => $source->id,
            'dataset_type' => 'certification_batches',
        ])->assertCreated()->assertJsonPath('batch.status', 'validated')->json('batch.id');

        $this->actingAs($head)->postJson("/api/integrations/batches/{$batchId}/publish")->assertOk();
        $this->assertSame('2026-10-17 10:00:00', $batch->fresh()->certificate_due_at->format('Y-m-d H:i:s'));
    }

    public function test_integration_cannot_regress_resolved_it_incident(): void
    {
        $head = User::where('email', 'it@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();
        $service = ItService::query()->whereNull('archived_at')->firstOrFail();
        $incident = ItIncident::query()->create([
            'it_service_id' => $service->id,
            'reference' => 'INC-INT-LIFE-001',
            'severity' => 'high',
            'status' => 'resolved',
            'started_at' => '2026-09-20 10:00:00',
            'acknowledged_at' => '2026-09-20 10:05:00',
            'resolved_at' => '2026-09-20 11:00:00',
            'summary' => 'Resolved incident',
            'resolution_summary' => 'Recovered',
            'root_cause' => 'Regression test',
            'resolved_by' => $head->id,
        ]);

        $header = "reference,service_name,severity,status,started_at,acknowledged_at,resolved_at,summary,resolution_summary,root_cause
";
        $row = "{$incident->reference},{$service->name},high,investigating,2026-09-20 10:00:00,2026-09-20 10:05:00,,Attempt reopen,,Regression test
";
        $batchId = $this->actingAs($head)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('it-regression.csv', $header.$row),
            'data_source_id' => $source->id,
            'dataset_type' => 'it_incidents',
        ])->assertCreated()->assertJsonPath('batch.status', 'validated')->json('batch.id');

        $this->actingAs($head)->postJson("/api/integrations/batches/{$batchId}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertSame('resolved', $incident->fresh()->status);
    }

    public function test_department_head_cannot_onboard_another_domains_dataset(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $source = DataSource::where('code', 'SRC-MANUAL')->firstOrFail();

        $this->actingAs($finance)->post('/api/integrations/stage', [
            'file' => UploadedFile::fake()->createWithContent('scheme.csv', "code,name,category,units_count,is_active,valid_until,evidence_reference\nSCH-DENIED,Denied,Produksi,4,1,2027-12-31,DOC\n"),
            'data_source_id' => $source->id,
            'dataset_type' => 'certification_schemes',
        ])->assertForbidden();
    }
}
