<?php

namespace Tests\Feature;

use App\Services\ReleaseReadinessService;
use Tests\TestCase;

class ReleaseReadinessTest extends TestCase
{
    public function test_readiness_endpoint_never_exposes_database_exception_details(): void
    {
        $response = $this->getJson('/api/health/ready');
        $this->assertContains($response->status(), [200, 503]);
        $response->assertJsonStructure([
            'status',
            'checks' => [['name', 'status']],
            'checked_at',
        ]);
        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
    }

    public function test_liveness_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_production_readiness_rejects_template_database_credentials_and_url(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://nadi.example.co.id',
            'database.default' => 'mysql',
            'database.connections.mysql.username' => 'nadi_app',
            'database.connections.mysql.password' => 'CHANGE_ME',
            'session.driver' => 'database',
            'cache.default' => 'database',
            'queue.default' => 'database',
            'session.encrypt' => true,
            'session.secure' => true,
            'nadi.demo_mode' => false,
            'nadi.allow_demo_seed' => false,
        ]);

        $result = app(ReleaseReadinessService::class)->evaluate(
            production: true,
            includeDatabase: false,
            includeBuild: false,
        );
        $checks = collect($result['checks'])->keyBy('name');

        $this->assertSame('fail', $checks['database_credentials_configured']['status']);
        $this->assertSame('fail', $checks['production_url_configured']['status']);
    }

    public function test_production_readiness_rejects_low_entropy_application_key(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => 'base64:'.base64_encode(str_repeat("\0", 32)),
            'app.cipher' => 'AES-256-CBC',
            'app.url' => 'https://nadi.company.example',
            'database.default' => 'mysql',
            'database.connections.mysql.username' => 'nadi_app',
            'database.connections.mysql.password' => 'not-a-placeholder',
            'session.driver' => 'database',
            'cache.default' => 'database',
            'queue.default' => 'database',
            'session.encrypt' => true,
            'session.secure' => true,
            'nadi.demo_mode' => false,
            'nadi.allow_demo_seed' => false,
        ]);

        $result = app(ReleaseReadinessService::class)->evaluate(
            production: true,
            includeDatabase: false,
            includeBuild: false,
        );
        $checks = collect($result['checks'])->keyBy('name');

        $this->assertSame('fail', $checks['application_key']['status']);
    }

    public function test_production_readiness_requires_durable_cache_and_queue_backends(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://nadi.company.example',
            'database.default' => 'mysql',
            'database.connections.mysql.username' => 'nadi_app',
            'database.connections.mysql.password' => 'not-a-placeholder',
            'session.driver' => 'database',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.encrypt' => true,
            'session.secure' => true,
            'nadi.demo_mode' => false,
            'nadi.allow_demo_seed' => false,
        ]);

        $result = app(ReleaseReadinessService::class)->evaluate(
            production: true,
            includeDatabase: false,
            includeBuild: false,
        );
        $checks = collect($result['checks'])->keyBy('name');

        $this->assertSame('fail', $checks['cache_database']['status']);
        $this->assertSame('fail', $checks['queue_database']['status']);
    }

}
