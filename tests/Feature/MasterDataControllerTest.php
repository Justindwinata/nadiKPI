<?php

namespace Tests\Feature;

use App\Models\CertificationScheme;
use App\Models\User;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeSeeder::class);
    }

    public function test_returns_401_when_master_data_is_requested_without_authentication(): void
    {
        $this->getJson('/api/master-data')->assertUnauthorized();
    }

    public function test_director_receives_every_master_data_catalog(): void
    {
        $director = User::where('role', 'director')->firstOrFail();

        $response = $this->actingAs($director)->getJson('/api/master-data');

        $response->assertOk()
            ->assertJsonCount(4, 'available_types')
            ->assertJsonStructure([
                'catalogs' => ['certification-schemes', 'tuks', 'assessors', 'it-services'],
                'activity',
                'synced_at',
            ]);
    }

    public function test_certification_head_receives_only_certification_catalogs(): void
    {
        $certificationHead = User::where('email', 'sertifikasi@demo.test')->firstOrFail();

        $response = $this->actingAs($certificationHead)->getJson('/api/master-data');

        $response->assertOk()
            ->assertJsonCount(3, 'available_types')
            ->assertJsonMissingPath('catalogs.it-services');
    }

    public function test_finance_head_receives_403_for_master_data_center(): void
    {
        $financeHead = User::where('email', 'keuangan@demo.test')->firstOrFail();

        $this->actingAs($financeHead)->getJson('/api/master-data')->assertForbidden();
    }

    public function test_certification_head_can_create_scheme_and_audit_record(): void
    {
        $certificationHead = User::where('email', 'sertifikasi@demo.test')->firstOrFail();

        $response = $this->actingAs($certificationHead)->postJson('/api/master-data/certification-schemes', [
            'code' => 'SRT-DRL-001',
            'name' => 'Ahli Drilling Operations',
            'category' => 'Okupasi',
            'units_count' => 12,
            'is_active' => true,
            'unexpected_admin_flag' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('type', 'certification-schemes')
            ->assertJsonPath('record.code', 'SRT-DRL-001');
        $this->assertDatabaseHas('certification_schemes', [
            'code' => 'SRT-DRL-001',
            'name' => 'Ahli Drilling Operations',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'entity_type' => CertificationScheme::class,
        ]);
    }

    public function test_certification_head_receives_403_when_creating_it_service(): void
    {
        $certificationHead = User::where('email', 'sertifikasi@demo.test')->firstOrFail();

        $this->actingAs($certificationHead)->postJson('/api/master-data/it-services', [
            'name' => 'API Integrasi Baru',
            'owner' => 'Tim Aplikasi',
            'target_uptime' => 99.5,
            'status' => 'operational',
        ])->assertForbidden();

        $this->assertDatabaseMissing('it_services', ['name' => 'API Integrasi Baru']);
    }

    public function test_duplicate_scheme_code_returns_422_with_actionable_message(): void
    {
        $director = User::where('role', 'director')->firstOrFail();
        $scheme = CertificationScheme::firstOrFail();

        $response = $this->actingAs($director)->postJson('/api/master-data/certification-schemes', [
            'code' => $scheme->code,
            'name' => 'Skema Duplikat',
            'category' => 'Okupasi',
            'units_count' => 5,
            'is_active' => true,
        ]);

        $response->assertUnprocessable()
            ->assertInvalid(['code' => 'kode sudah digunakan.']);
    }
}
