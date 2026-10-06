<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\RiskSignal;
use App\Models\ManagementReviewItem;
use App\Models\ManagementReview;
use App\Models\ActionItem;
use App\Models\AuthenticationEvent;
use App\Models\CertificateIssuance;
use App\Models\CertificationAppeal;
use App\Models\CertificationBatch;
use App\Models\ComplianceFinding;
use App\Models\DataImportBatch;
use App\Models\DataSource;
use App\Models\FinancialRecord;
use App\Models\ReportSnapshot;
use App\Models\User;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ImmutableLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_financial_ledger_rejects_eloquent_update_and_delete(): void
    {
        $record = FinancialRecord::query()->firstOrFail();

        try {
            $record->update(['description' => 'tampered']);
            $this->fail('FinancialRecord update should be rejected.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(LogicException::class);
        $record->fresh()->delete();
    }

    public function test_certificate_issuance_ledger_rejects_eloquent_update_and_delete(): void
    {
        $issuance = CertificateIssuance::query()->firstOrFail();

        try {
            $issuance->update(['notes' => 'tampered']);
            $this->fail('CertificateIssuance update should be rejected.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(LogicException::class);
        $issuance->fresh()->delete();
    }

    public function test_certification_batch_with_issuance_cannot_be_deleted_through_eloquent(): void
    {
        $batch = CertificationBatch::query()->whereHas('issuances')->firstOrFail();

        $this->expectException(LogicException::class);
        $batch->delete();
    }


    public function test_security_audit_ledgers_reject_eloquent_update_and_delete(): void
    {
        $user = User::query()->firstOrFail();
        $audit = AuditLog::create([
            'user_id' => $user->id,
            'action' => 'immutable_test',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'changes' => ['test' => true],
            'ip_address' => '127.0.0.1',
        ]);
        $authEvent = AuthenticationEvent::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'event' => 'immutable_test',
            'successful' => true,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'occurred_at' => now(),
        ]);

        foreach ([$audit, $authEvent] as $event) {
            try {
                $event->update(['ip_address' => '10.0.0.1']);
                $this->fail('Append-only security event update should be rejected.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }

            try {
                $event->fresh()->delete();
                $this->fail('Append-only security event delete should be rejected.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }


    public function test_data_import_batch_identity_is_immutable_but_lifecycle_fields_remain_mutable(): void
    {
        $batch = DataImportBatch::query()->firstOrFail();

        try {
            $batch->update(['sha256' => str_repeat('a', 64)]);
            $this->fail('Import batch SHA identity should be immutable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $batch->fresh()->update(['reconciliation_status' => 'reviewing']);
        $this->assertDatabaseHas('data_import_batches', [
            'id' => $batch->id,
            'reconciliation_status' => 'reviewing',
        ]);

        $this->expectException(LogicException::class);
        $batch->fresh()->delete();
    }

    public function test_parent_records_with_governance_or_provenance_evidence_cannot_be_deleted(): void
    {
        $finding = ComplianceFinding::query()->whereHas('actions')->firstOrFail();
        try {
            $finding->delete();
            $this->fail('Finding with CAPA evidence should not be deletable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $source = DataSource::query()->whereHas('importBatches')->firstOrFail();
        try {
            $source->delete();
            $this->fail('Data source with provenance/import evidence should not be deletable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $template = CertificationBatch::query()->whereDoesntHave('issuances')->firstOrFail();
        $appealBatch = $template->replicate();
        $appealBatch->code = 'IMM-APPEAL-'.$template->id;
        $appealBatch->save();
        CertificationAppeal::create([
            'certification_batch_id' => $appealBatch->id,
            'reference' => 'IMM-APL-'.$appealBatch->id,
            'appellant_reference' => 'IMM-ASESI',
            'received_at' => now(),
            'due_at' => now()->addDays(7),
            'reason' => 'Immutable parent test',
            'status' => 'received',
            'owner_name' => 'Quality',
            'created_by' => User::query()->firstOrFail()->id,
        ]);

        $this->expectException(LogicException::class);
        $appealBatch->delete();
    }


    public function test_identity_and_decision_evidence_cannot_be_hard_deleted_through_eloquent(): void
    {
        $records = [
            User::query()->firstOrFail(),
            RiskSignal::query()->firstOrFail(),
            ActionItem::query()->firstOrFail(),
            ManagementReview::query()->firstOrFail(),
            ManagementReviewItem::query()->firstOrFail(),
        ];

        foreach ($records as $record) {
            try {
                $record->delete();
                $this->fail($record::class.' should reject hard delete.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_mysql_database_triggers_reject_direct_ledger_mutation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-trigger immutability is a production MySQL invariant.');
        }

        $record = FinancialRecord::query()->firstOrFail();
        $issuance = CertificateIssuance::query()->firstOrFail();

        foreach ([
            fn () => DB::table('financial_records')->where('id', $record->id)->update(['description' => 'tampered']),
            fn () => DB::table('certificate_issuances')->where('id', $issuance->id)->update(['notes' => 'tampered']),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('MySQL immutable-ledger trigger should reject direct mutation.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $issuedBatch = CertificationBatch::query()->whereHas('issuances')->firstOrFail();
        try {
            DB::table('certification_batches')->where('id', $issuedBatch->id)->delete();
            $this->fail('MySQL parent-protection trigger should reject deletion of a batch with certificate issuances.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $audit = AuditLog::query()->firstOrFail();
        $authEvent = AuthenticationEvent::create([
            'user_id' => User::query()->firstOrFail()->id,
            'email' => 'immutable@demo.test',
            'event' => 'immutable_mysql_test',
            'successful' => true,
            'occurred_at' => now(),
        ]);
        foreach ([
            fn () => DB::table('audit_logs')->where('id', $audit->id)->update(['ip_address' => '10.0.0.1']),
            fn () => DB::table('authentication_events')->where('id', $authEvent->id)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('MySQL append-only audit trigger should reject direct mutation.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $importBatch = DataImportBatch::query()->firstOrFail();
        foreach ([
            fn () => DB::table('data_import_batches')->where('id', $importBatch->id)->update(['sha256' => str_repeat('b', 64)]),
            fn () => DB::table('data_import_batches')->where('id', $importBatch->id)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('MySQL provenance-batch trigger should reject identity mutation/deletion.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        DB::table('data_import_batches')->where('id', $importBatch->id)->update(['reconciliation_status' => 'reviewing']);
        $this->assertDatabaseHas('data_import_batches', ['id' => $importBatch->id, 'reconciliation_status' => 'reviewing']);

        $finding = ComplianceFinding::query()->whereHas('actions')->firstOrFail();
        try {
            DB::table('compliance_findings')->where('id', $finding->id)->delete();
            $this->fail('MySQL parent-protection trigger should reject deletion of a finding with CAPA evidence.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $source = DataSource::query()->whereHas('importBatches')->firstOrFail();
        try {
            DB::table('data_sources')->where('id', $source->id)->delete();
            $this->fail('MySQL parent-protection trigger should reject deletion of a source with provenance evidence.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        foreach ([
            ['users', User::query()->firstOrFail()->id],
            ['risk_signals', RiskSignal::query()->firstOrFail()->id],
            ['action_items', ActionItem::query()->firstOrFail()->id],
            ['management_reviews', ManagementReview::query()->firstOrFail()->id],
            ['management_review_items', ManagementReviewItem::query()->firstOrFail()->id],
        ] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('MySQL identity/decision evidence trigger should reject direct deletion.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $snapshotId = $this->actingAs($director)->postJson('/api/reports', [
            'report_type' => 'executive', 'year' => 2026, 'month' => 9,
        ])->assertCreated()->json('report.id');
        $this->assertNotNull(ReportSnapshot::find($snapshotId));

        $this->expectException(QueryException::class);
        DB::table('report_snapshots')->where('id', $snapshotId)->delete();
    }
}
