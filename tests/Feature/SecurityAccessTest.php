<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\FinanceInvoice;
use App\Models\User;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
        RateLimiter::clear('login:unknown@example.test|127.0.0.1');
        RateLimiter::clear('login-ip:127.0.0.1');
    }

    public function test_viewer_can_read_own_module_but_cannot_mutate_certification(): void
    {
        $viewer = User::where('email', 'sertifikasi@demo.test')->firstOrFail();
        $viewer->update(['role' => 'viewer']);

        $this->actingAs($viewer)->getJson('/api/certification')->assertOk();
        $this->actingAs($viewer)->postJson('/api/certification/batches', [])->assertForbidden();
    }

    public function test_finance_analyst_can_create_invoice_but_cannot_reverse_ledger(): void
    {
        $department = Department::where('code', 'finance')->firstOrFail();
        $analyst = User::create([
            'name' => 'Finance Analyst',
            'email' => 'finance.analyst@test.local',
            'department_id' => $department->id,
            'role' => 'analyst',
            'position' => 'Finance Analyst',
            'password' => 'ValidPass123!',
            'is_active' => true,
        ]);

        $response = $this->actingAs($analyst)->postJson('/api/finance/invoices', [
            'invoice_number' => 'INV-SEC-001',
            'issued_on' => today()->toDateString(),
            'due_on' => today()->addDays(30)->toDateString(),
            'customer_name' => 'PT Test Security',
            'category' => 'Jasa sertifikasi',
            'department_code' => 'finance',
            'amount' => 10000000,
            'description' => 'Invoice security test',
        ])->assertCreated();

        $invoice = FinanceInvoice::findOrFail($response->json('invoice.id'));
        $this->actingAs($analyst)
            ->postJson("/api/finance/records/{$invoice->revenue_record_id}/reverse", [
                'recorded_on' => today()->toDateString(),
                'reason' => 'Tidak seharusnya diizinkan',
            ])
            ->assertForbidden();
    }

    public function test_admin_created_user_must_change_temporary_password_before_using_modules(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $department = Department::where('code', 'it')->firstOrFail();

        $userId = $this->actingAs($director)->postJson('/api/admin/users', [
            'name' => 'Temporary User',
            'email' => 'temporary.user@test.local',
            'department_id' => $department->id,
            'role' => 'viewer',
            'position' => 'Viewer IT',
            'temporary_password' => 'Temporary123!',
            'temporary_password_confirmation' => 'Temporary123!',
        ])->assertCreated()->json('user.id');

        $user = User::findOrFail($userId);
        $this->assertTrue($user->must_change_password);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertStatus(423)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        $this->actingAs($user)->postJson('/api/account/password', [
            'current_password' => 'Temporary123!',
            'password' => 'PermanentPass456!',
            'password_confirmation' => 'PermanentPass456!',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        $this->actingAs($user->fresh())->getJson('/api/dashboard')->assertOk();
    }

    public function test_create_admin_command_can_mark_acceptance_password_as_temporary(): void
    {
        $email = 'forced.acceptance@test.local';

        $this->artisan('nadi:create-admin', [
            'email' => $email,
            '--name' => 'Forced Acceptance',
            '--password' => 'TemporaryPass123!',
            '--force-password-change' => true,
        ])->assertSuccessful();

        $user = User::where('email', $email)->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertStatus(423)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_director_can_deny_module_permission_with_audited_override(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $finance = User::where('email', 'keuangan@demo.test')->firstOrFail();

        $this->actingAs($director)->putJson("/api/admin/users/{$finance->id}/permissions", [
            'overrides' => [[
                'permission' => 'finance.view',
                'allowed' => false,
                'reason' => 'Temporary segregation-of-duties test',
            ]],
        ])->assertOk();

        $this->actingAs($finance->fresh())->getJson('/api/finance')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update_permissions',
            'entity_type' => User::class,
            'entity_id' => $finance->id,
        ]);
    }

    public function test_login_is_rate_limited_and_failed_attempts_are_logged(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'unknown@example.test',
                'password' => 'WrongPassword!',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'unknown@example.test',
            'password' => 'WrongPassword!',
        ])->assertStatus(429)->assertHeader('Retry-After');

        $this->assertDatabaseCount('authentication_events', 6);
        $this->assertDatabaseHas('authentication_events', [
            'email' => 'unknown@example.test',
            'event' => 'login_throttled',
            'successful' => false,
        ]);
    }

    public function test_admin_cannot_disable_current_account_or_remove_last_active_director(): void
    {
        $director = User::where('role', 'director')->firstOrFail();

        $this->actingAs($director)->patchJson("/api/admin/users/{$director->id}/status", [
            'is_active' => false,
            'reason' => 'Self disable test',
        ])->assertUnprocessable();

        $this->assertTrue($director->fresh()->is_active);
    }

    public function test_demo_access_endpoint_is_disabled_unless_demo_mode_is_enabled(): void
    {
        config(['nadi.demo_mode' => false]);
        $this->getJson('/api/demo-access')->assertNotFound();

        config(['nadi.demo_mode' => true, 'nadi.demo_password' => '']);
        $this->getJson('/api/demo-access')->assertStatus(503);

        config(['nadi.demo_mode' => true, 'nadi.demo_password' => 'DemoAccess123!']);
        $this->getJson('/api/demo-access')
            ->assertOk()
            ->assertJsonPath('password', 'DemoAccess123!')
            ->assertJsonFragment(['email' => 'pimpinan@demo.test']);
    }

    public function test_inactive_user_is_blocked_even_if_a_stale_authenticated_session_exists(): void
    {
        $user = User::where('email', 'keuangan@demo.test')->firstOrFail();
        $user->update(['is_active' => false, 'deactivated_at' => now()]);

        $this->actingAs($user)->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        $this->actingAs($user)->postJson('/api/account/password', [
            'current_password' => 'irrelevant',
            'password' => 'AnotherPass123!',
            'password_confirmation' => 'AnotherPass123!',
        ])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');

        $this->actingAs($user)->postJson('/api/logout')->assertOk();
    }

    public function test_security_headers_are_present_and_hsts_requires_https_in_production(): void
    {
        config(['app.env' => 'production']);

        $http = $this->get('/up');
        $http->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy')
            ->assertHeader('Content-Security-Policy');
        $this->assertFalse($http->headers->has('Strict-Transport-Security'));

        $https = $this->get('https://nadi.example.test/up');
        $https->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->assertHeader('Content-Security-Policy');

        $csp = (string) $https->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
    }
}
