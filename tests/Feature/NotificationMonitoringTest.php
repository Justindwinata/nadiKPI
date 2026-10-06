<?php

namespace Tests\Feature;

use App\Models\ActionItem;
use App\Models\Department;
use App\Models\RiskSignal;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationMonitoringService;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_monitor_creates_department_scoped_notifications_without_duplicates(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
        $finance = Department::where('code', 'finance')->firstOrFail();
        $signal = RiskSignal::create($this->manualSignal('notify-finance', $finance->id, 'high', now()));

        $monitor = app(NotificationMonitoringService::class);
        $monitor->monitor(now());

        $financeHead = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $director = User::where('email', 'pimpinan@demo.test')->firstOrFail();
        $itHead = User::where('email', 'it@demo.test')->firstOrFail();

        $this->assertDatabaseHas('user_notifications', ['user_id' => $financeHead->id, 'risk_signal_id' => $signal->id, 'type' => 'risk_signal']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $director->id, 'risk_signal_id' => $signal->id, 'type' => 'risk_signal']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $itHead->id, 'risk_signal_id' => $signal->id]);

        $count = UserNotification::where('risk_signal_id', $signal->id)->count();
        $monitor->monitor(now());
        $this->assertSame($count, UserNotification::where('risk_signal_id', $signal->id)->count());
        Carbon::setTestNow();
    }

    public function test_unacknowledged_critical_signal_is_auto_escalated_and_audited(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
        config()->set('nadi.monitoring.critical_level1_minutes', 60);
        config()->set('nadi.monitoring.critical_level2_minutes', 240);
        $finance = Department::where('code', 'finance')->firstOrFail();
        $signal = RiskSignal::create($this->manualSignal('critical-escalation', $finance->id, 'critical', now()->subMinutes(90)));

        app(NotificationMonitoringService::class)->monitor(now());

        $signal->refresh();
        $this->assertSame(1, $signal->escalation_level);
        $this->assertNotNull($signal->auto_escalated_at);
        $this->assertNull($signal->acknowledged_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auto_escalate_risk_signal', 'entity_id' => $signal->id]);
        $this->assertTrue(UserNotification::where('risk_signal_id', $signal->id)->where('type', 'risk_escalated')->exists());
        Carbon::setTestNow();
    }

    public function test_overdue_action_reminder_is_daily_and_idempotent_within_day(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
        $finance = Department::where('code', 'finance')->firstOrFail();
        $owner = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $action = ActionItem::create([
            'department_id' => $finance->id,
            'title' => 'Rekonsiliasi piutang overdue',
            'description' => 'Follow-up invoice overdue.',
            'priority' => 'high',
            'owner_name' => 'Dimas Wicaksono',
            'due_date' => now()->subDay()->toDateString(),
            'status' => 'open',
            'created_by' => $owner->id,
        ]);

        $monitor = app(NotificationMonitoringService::class);
        $monitor->monitor(now());
        $firstCount = UserNotification::where('action_item_id', $action->id)->where('type', 'action_overdue')->count();
        $this->assertGreaterThan(0, $firstCount);

        $monitor->monitor(now()->addHours(2));
        $this->assertSame($firstCount, UserNotification::where('action_item_id', $action->id)->where('type', 'action_overdue')->count());

        $monitor->monitor(now()->addDay());
        $this->assertGreaterThan($firstCount, UserNotification::where('action_item_id', $action->id)->where('type', 'action_overdue')->count());
        Carbon::setTestNow();
    }

    public function test_notification_owner_can_read_and_acknowledge_but_other_user_cannot(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $it = User::where('email', 'it@demo.test')->firstOrFail();
        $notification = UserNotification::create([
            'user_id' => $finance->id,
            'fingerprint' => 'test-owner-notification',
            'type' => 'risk_signal',
            'severity' => 'critical',
            'title' => 'Test notification',
            'message' => 'Owner-only notification.',
            'action_url' => '/decisions',
        ]);

        $this->actingAs($it)->postJson("/api/notifications/{$notification->id}/read")->assertNotFound();

        $this->actingAs($finance)->postJson("/api/notifications/{$notification->id}/read")
            ->assertOk()->assertJsonPath('notification.id', $notification->id);
        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($finance)->postJson("/api/notifications/{$notification->id}/acknowledge")
            ->assertOk();
        $this->assertNotNull($notification->fresh()->acknowledged_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'acknowledge_notification', 'entity_id' => $notification->id]);
    }

    private function manualSignal(string $fingerprint, int $departmentId, string $severity, Carbon $detectedAt): array
    {
        return [
            'fingerprint' => $fingerprint,
            'rule_code' => 'MANUAL_TEST',
            'category' => 'test',
            'severity' => $severity,
            'status' => 'open',
            'department_id' => $departmentId,
            'source_type' => 'test',
            'source_reference' => strtoupper($fingerprint),
            'title' => 'Signal '.$fingerprint,
            'description' => 'Signal pengujian notification monitoring.',
            'detected_at' => $detectedAt,
            'last_observed_at' => $detectedAt,
            'due_at' => $detectedAt->copy()->addDays(3),
        ];
    }
}
