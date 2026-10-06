<?php

namespace Tests\Feature;

use App\Models\ActionItem;
use App\Models\KpiDefinition;
use App\Models\User;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_director_receives_calculated_cross_division_dashboard(): void
    {
        $director = User::where('role', 'director')->firstOrFail();

        $response = $this->actingAs($director)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonCount(9, 'overview.kpis')
            ->assertJsonCount(3, 'overview.departments');

        $byCode = collect($response->json('overview.kpis'))->keyBy('code');
        $this->assertTrue($byCode['CERT-VOLUME']['is_system_derived']);
        $this->assertSame('system_derived', $byCode['CERT-PASS']['source_type']);
        $this->assertIsNumeric($response->json('overview.overall_score'));
    }

    public function test_department_head_can_only_open_own_operational_module(): void
    {
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();

        $this->actingAs($finance)->getJson('/api/finance')->assertOk();
        $this->actingAs($finance)->getJson('/api/certification')->assertForbidden();
    }

    public function test_reporting_period_filters_finance_window_and_returns_metadata(): void
    {
        $director = User::where('role', 'director')->firstOrFail();

        $response = $this->actingAs($director)->getJson('/api/finance?year=2001&month=2');

        $response->assertOk()
            ->assertJsonPath('period.as_of', '2001-02-28')
            ->assertJsonPath('summary.revenue', 0)
            ->assertJsonCount(12, 'monthly');
    }

    public function test_reporting_period_rejects_invalid_month(): void
    {
        $director = User::where('role', 'director')->firstOrFail();

        $this->actingAs($director)->getJson('/api/it?month=13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_historical_overview_uses_action_state_as_of_cutoff_and_excludes_future_actions(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $department = $director->department_id ? $director->department : \App\Models\Department::where('code', 'finance')->firstOrFail();

        $baseline = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=8')->assertOk();
        $baselineOpen = (int) $baseline->json('overview.open_actions');
        $baselineOverdue = (int) $baseline->json('overview.overdue_actions');

        ActionItem::query()->create([
            'department_id' => $department->id,
            'title' => 'Action Agustus yang baru selesai September',
            'description' => 'Harus tetap open pada snapshot Agustus.',
            'priority' => 'high',
            'status' => 'completed',
            'owner_name' => 'QA',
            'due_date' => '2026-08-20',
            'completed_at' => '2026-09-05 10:00:00',
            'created_by' => $director->id,
            'created_at' => '2026-08-10 08:00:00',
            'updated_at' => '2026-09-05 10:00:00',
        ]);

        ActionItem::query()->create([
            'department_id' => $department->id,
            'title' => 'Action yang baru dibuat September',
            'priority' => 'medium',
            'status' => 'open',
            'owner_name' => 'QA',
            'due_date' => '2026-09-20',
            'created_by' => $director->id,
            'created_at' => '2026-09-10 08:00:00',
            'updated_at' => '2026-09-10 08:00:00',
        ]);

        $historical = $this->actingAs($director)->getJson('/api/dashboard?year=2026&month=8')
            ->assertOk();

        $this->assertSame($baselineOpen + 1, (int) $historical->json('overview.open_actions'));
        $this->assertSame($baselineOverdue + 1, (int) $historical->json('overview.overdue_actions'));
        $lastUpdated = $historical->json('overview.last_updated_at');
        if ($lastUpdated) {
            $this->assertLessThanOrEqual(
                strtotime('2026-08-31 23:59:59'),
                strtotime($lastUpdated),
                'Historical overview last_updated_at must not leak a future mutation.',
            );
        }
    }

    public function test_action_updates_are_persisted_and_audited(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $action = ActionItem::firstOrFail();

        $this->actingAs($director)->patchJson("/api/actions/{$action->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('action.status', 'completed');

        $this->assertDatabaseHas('audit_logs', ['action' => 'status_change']);
        $this->assertNotNull($action->fresh()->completed_at);
    }
}
