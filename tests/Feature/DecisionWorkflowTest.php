<?php

namespace Tests\Feature;

use App\Models\ActionItem;
use App\Models\Department;
use App\Models\ManagementReview;
use App\Models\RiskSignal;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\RiskSignalService;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DecisionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_risk_engine_is_idempotent_and_decision_get_is_side_effect_free(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $service = app(RiskSignalService::class);

        $service->sync();
        $firstCount = RiskSignal::count();
        $this->assertGreaterThan(0, $firstCount);

        $response = $this->actingAs($director)->getJson('/api/decisions')->assertOk();
        $this->assertSame($firstCount, RiskSignal::count(), 'GET /api/decisions must not run the risk monitor or mutate signals.');

        $service->sync();
        $this->assertSame($firstCount, RiskSignal::count());
        $this->assertSame($firstCount, RiskSignal::distinct('fingerprint')->count('fingerprint'));
        $this->assertGreaterThan(0, $response->json('summary.active_signals'));
    }

    public function test_active_signal_due_date_does_not_drift_on_repeated_monitor_sync(): void
    {
        $service = app(RiskSignalService::class);
        Carbon::setTestNow('2026-09-30 10:00:00');
        $service->sync();
        $signal = RiskSignal::where('rule_code', 'KPI_EXCEPTION')->firstOrFail();
        $dueAt = $signal->due_at?->toDateTimeString();

        Carbon::setTestNow('2026-09-30 14:00:00');
        $service->sync();

        $this->assertSame($dueAt, $signal->fresh()->due_at?->toDateTimeString());
        Carbon::setTestNow();
    }

    public function test_department_head_only_sees_signals_for_own_department(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $financeDepartment = Department::where('code', 'finance')->firstOrFail();
        $certificationDepartment = Department::where('code', 'certification')->firstOrFail();

        RiskSignal::create($this->manualSignal('manual-finance', $financeDepartment->id, 'Finance signal'));
        RiskSignal::create($this->manualSignal('manual-certification', $certificationDepartment->id, 'Certification signal'));

        $response = $this->actingAs($finance)->getJson('/api/decisions')->assertOk();
        $fingerprints = collect($response->json('signals'))->pluck('fingerprint');

        $this->assertTrue($fingerprints->contains('manual-finance'));
        $this->assertFalse($fingerprints->contains('manual-certification'));
    }

    public function test_signal_acknowledgement_escalation_and_linked_action_are_audited(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $department = Department::where('code', 'finance')->firstOrFail();
        $signal = RiskSignal::create($this->manualSignal('manual-workflow', $department->id, 'Piutang strategis'));

        $this->actingAs($finance)->postJson("/api/decisions/signals/{$signal->id}/acknowledge")
            ->assertOk()->assertJsonPath('signal.status', 'acknowledged');

        $this->actingAs($finance)->postJson("/api/decisions/signals/{$signal->id}/escalate", ['reason' => 'Nilai material dan sudah melewati tenggat.'])
            ->assertOk()->assertJsonPath('signal.escalation_level', 1);

        $actionResponse = $this->actingAs($finance)->postJson("/api/decisions/signals/{$signal->id}/actions", [
            'title' => 'Hubungi pelanggan dan tetapkan recovery plan',
            'description' => 'Validasi invoice, komitmen pembayaran, dan eskalasi bila tidak ada kepastian.',
            'priority' => 'high',
            'owner_name' => 'Dimas Wicaksono',
            'due_date' => today()->addDays(3)->format('Y-m-d'),
            'decision_reference' => 'MR-FIN-001',
        ])->assertCreated();

        $actionId = $actionResponse->json('action.id');
        $this->assertDatabaseHas('action_items', ['id' => $actionId, 'risk_signal_id' => $signal->id, 'decision_reference' => 'MR-FIN-001']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'acknowledge_risk_signal', 'entity_id' => $signal->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'escalate_risk_signal', 'entity_id' => $signal->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'create_decision_action', 'entity_id' => $actionId]);
    }

    public function test_closed_signal_rejects_new_action_and_second_resolution(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $department = Department::where('code', 'finance')->firstOrFail();
        $signal = RiskSignal::create($this->manualSignal('manual-closed-guard', $department->id, 'Closed signal guard'));

        $this->actingAs($finance)->postJson("/api/decisions/signals/{$signal->id}/resolve", [
            'resolution_note' => 'Risiko telah diselesaikan dengan bukti rekonsiliasi yang memadai.',
        ])->assertOk()->assertJsonPath('signal.status', 'resolved');

        $this->actingAs($finance)->postJson("/api/decisions/signals/{$signal->id}/resolve", [
            'resolution_note' => 'Percobaan resolusi kedua harus ditolak oleh lifecycle guard.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['signal']);

        $this->actingAs($finance)->postJson("/api/decisions/signals/{$signal->id}/actions", [
            'title' => 'Action after closed signal',
            'description' => 'Action baru tidak boleh ditambahkan setelah signal ditutup.',
            'priority' => 'high',
            'owner_name' => 'Kepala Keuangan',
            'due_date' => today()->addDays(3)->format('Y-m-d'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['signal']);
    }

    public function test_action_completion_requires_resolution_evidence_and_closes_linked_signal(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $department = Department::where('code', 'finance')->firstOrFail();
        $signal = RiskSignal::create($this->manualSignal('manual-complete', $department->id, 'Resolve me'));
        $action = ActionItem::create([
            'department_id' => $department->id,
            'risk_signal_id' => $signal->id,
            'title' => 'Complete corrective action',
            'priority' => 'high',
            'status' => 'in_progress',
            'owner_name' => 'Dimas Wicaksono',
            'due_date' => today()->addDay(),
            'created_by' => $finance->id,
        ]);

        $this->actingAs($finance)->patchJson("/api/actions/{$action->id}", ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resolution_note', 'resolution_evidence']);

        $this->actingAs($finance)->patchJson("/api/actions/{$action->id}", [
            'status' => 'completed',
            'resolution_note' => 'Pembayaran diterima dan rekonsiliasi invoice telah selesai.',
            'resolution_evidence' => 'PAY-REC-001',
        ])->assertOk()->assertJsonPath('action.status', 'completed');

        $this->assertDatabaseHas('risk_signals', ['id' => $signal->id, 'status' => 'resolved']);
    }

    public function test_linked_signal_only_auto_resolves_after_all_actions_are_completed(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $department = Department::where('code', 'finance')->firstOrFail();
        $signal = RiskSignal::create($this->manualSignal('manual-multi-action', $department->id, 'Multiple actions required'));

        $first = ActionItem::create([
            'department_id' => $department->id,
            'risk_signal_id' => $signal->id,
            'title' => 'Action pertama',
            'priority' => 'high',
            'status' => 'in_progress',
            'owner_name' => 'Dimas Wicaksono',
            'due_date' => today()->addDay(),
            'created_by' => $finance->id,
        ]);
        $second = ActionItem::create([
            'department_id' => $department->id,
            'risk_signal_id' => $signal->id,
            'title' => 'Action kedua',
            'priority' => 'high',
            'status' => 'in_progress',
            'owner_name' => 'Kepala Keuangan',
            'due_date' => today()->addDays(2),
            'created_by' => $finance->id,
        ]);

        $this->actingAs($finance)->patchJson("/api/actions/{$first->id}", [
            'status' => 'completed',
            'resolution_note' => 'Action pertama selesai dengan bukti rekonsiliasi tahap satu.',
            'resolution_evidence' => 'EVIDENCE-ACTION-1',
        ])->assertOk();

        $this->assertNotSame('resolved', $signal->fresh()->status);

        $this->actingAs($finance)->patchJson("/api/actions/{$second->id}", [
            'status' => 'completed',
            'resolution_note' => 'Action kedua selesai dan seluruh tindak lanjut telah ditutup.',
            'resolution_evidence' => 'EVIDENCE-ACTION-2',
        ])->assertOk();

        $this->assertSame('resolved', $signal->fresh()->status);
    }

    public function test_management_review_rechecks_current_permission_and_signal_scope_inside_transaction(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        UserPermission::updateOrCreate(
            ['user_id' => $finance->id, 'permission' => 'decisions.review'],
            ['allowed' => true, 'reason' => 'Regression test scoped review permission'],
        );
        $finance->unsetRelation('permissionOverrides');

        $financeDepartment = Department::where('code', 'finance')->firstOrFail();
        $certificationDepartment = Department::where('code', 'certification')->firstOrFail();
        $ownSignal = RiskSignal::create($this->manualSignal('review-finance-scope', $financeDepartment->id, 'Finance review signal'));
        $otherSignal = RiskSignal::create($this->manualSignal('review-cert-scope', $certificationDepartment->id, 'Certification review signal'));

        $this->actingAs($finance)->postJson('/api/decisions/reviews', [
            'title' => 'Scoped management review',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'chair_name' => 'Kepala Keuangan',
            'signal_ids' => [$ownSignal->id, $otherSignal->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['signal_ids']);

        $this->assertDatabaseMissing('management_reviews', ['title' => 'Scoped management review']);
    }

    public function test_management_review_requires_decisions_before_approval_and_is_forward_only(): void
    {
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $department = Department::where('code', 'finance')->firstOrFail();
        $signal = RiskSignal::create($this->manualSignal('manual-review', $department->id, 'Agenda review'));

        $response = $this->actingAs($director)->postJson('/api/decisions/reviews', [
            'title' => 'Management Review September',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'meeting_at' => '2026-09-30 10:00:00',
            'chair_name' => 'Arif Rahman',
            'summary' => 'Review exception perusahaan.',
            'signal_ids' => [$signal->id],
        ])->assertCreated()->assertJsonPath('review.status', 'draft');

        $reviewId = $response->json('review.id');
        $itemId = $response->json('review.items.0.id');

        // Lifecycle tidak boleh melompati tahap in_review/approved.
        $this->actingAs($director)->patchJson("/api/decisions/reviews/{$reviewId}", [
            'status' => 'closed',
            'summary' => 'Percobaan lompat lifecycle.',
            'decisions' => 'Tidak boleh langsung ditutup.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->actingAs($director)->patchJson("/api/decisions/reviews/{$reviewId}", [
            'status' => 'in_review',
            'summary' => 'Review exception perusahaan.',
            'decisions' => null,
        ])->assertOk()->assertJsonPath('review.status', 'in_review');

        // Item juga forward-only selama review belum approved.
        $this->actingAs($director)->patchJson("/api/decisions/reviews/{$reviewId}/items/{$itemId}", [
            'status' => 'completed',
            'decision' => 'Item selesai dibahas.',
        ])->assertOk()->assertJsonPath('item.status', 'completed');
        $this->actingAs($director)->patchJson("/api/decisions/reviews/{$reviewId}/items/{$itemId}", [
            'status' => 'open',
            'decision' => 'Tidak boleh dibuka kembali.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->actingAs($director)->patchJson("/api/decisions/reviews/{$reviewId}", [
            'status' => 'approved',
            'summary' => 'Review exception perusahaan.',
            'decisions' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['decisions']);

        $this->actingAs($director)->patchJson("/api/decisions/reviews/{$reviewId}", [
            'status' => 'approved',
            'summary' => 'Review exception perusahaan.',
            'decisions' => 'Percepat collection dan laporkan progres setiap tiga hari.',
        ])->assertOk()->assertJsonPath('review.status', 'approved');

        $this->actingAs($director)->patchJson("/api/decisions/reviews/{$reviewId}", [
            'status' => 'draft',
            'summary' => 'Mundur.',
            'decisions' => 'Tidak boleh.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->actingAs($director)->patchJson("/api/decisions/reviews/{$reviewId}", [
            'status' => 'closed',
            'summary' => 'Review selesai.',
            'decisions' => 'Percepat collection dan laporkan progres setiap tiga hari.',
        ])->assertOk()->assertJsonPath('review.status', 'closed');

        $this->assertNotNull(ManagementReview::findOrFail($reviewId)->approved_at);
        $this->assertNotNull(ManagementReview::findOrFail($reviewId)->closed_at);
    }

    private function manualSignal(string $fingerprint, int $departmentId, string $title): array
    {
        return [
            'fingerprint' => $fingerprint,
            'rule_code' => 'MANUAL_TEST',
            'category' => 'test',
            'severity' => 'high',
            'status' => 'open',
            'department_id' => $departmentId,
            'source_type' => 'test',
            'source_reference' => strtoupper($fingerprint),
            'title' => $title,
            'description' => 'Signal pengujian decision workflow.',
            'detected_at' => now(),
            'last_observed_at' => now(),
            'due_at' => now()->addDays(3),
        ];
    }
}
