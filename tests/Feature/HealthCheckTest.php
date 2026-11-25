<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_positive_health_check_returns_ok_with_mysql_connectivity(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                'status',
                'timestamp',
                'services' => [
                    'database',
                    'redis',
                ],
            ]);

        $this->assertEquals('healthy', $response->json('status'));
        $this->assertEquals('connected', $response->json('services.database'));
        // Verify runtime details and raw DB names are NOT exposed when debug is false
        $this->assertNull($response->json('runtime'));
        $this->assertNull($response->json('diagnostics'));
    }

    public function test_positive_health_check_includes_diagnostics_when_debug_enabled(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'services',
                'runtime' => [
                    'php',
                    'laravel',
                    'environment',
                ],
                'diagnostics' => [
                    'database_latency_ms',
                ],
            ]);
    }

    public function test_positive_root_health_route_alias_works(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('services.database', 'connected');
    }

    public function test_positive_laravel_up_route_works(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    public function test_negative_health_check_returns_503_and_sanitizes_errors_when_debug_disabled(): void
    {
        config(['app.debug' => false]);
        config(['database.connections.mysql.host' => '127.0.0.1']);
        config(['database.connections.mysql.port' => '59999']);
        DB::purge('mysql');

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://api.usayd.academy/errors/service-unavailable')
            ->assertJsonPath('status', 503)
            ->assertJsonPath('services.database', 'unreachable');

        // Sensitive details must NOT leak
        $this->assertNull($response->json('debug'));
        $this->assertNull($response->json('error'));
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->json()));
        $this->assertStringNotContainsString('59999', json_encode($response->json()));
    }

    public function test_negative_health_check_includes_debug_only_when_debug_enabled(): void
    {
        config(['app.debug' => true]);
        config(['database.connections.mysql.host' => '127.0.0.1']);
        config(['database.connections.mysql.port' => '59999']);
        DB::purge('mysql');

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 503)
            ->assertJsonPath('services.database', 'unreachable');

        $this->assertNotNull($response->json('debug.database_error'));
    }

    public function test_redis_readiness_policy_marks_degraded_when_redis_required_and_unavailable(): void
    {
        config(['app.debug' => false]);
        config(['cache.default' => 'redis']);
        config(['database.redis.default.port' => 59998]);

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('services.redis', 'unreachable');
    }

    public function test_production_never_exposes_debug_diagnostics_even_if_enabled(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => true]);

        $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonMissingPath('runtime')
            ->assertJsonMissingPath('diagnostics');
    }

    public function test_failed_health_response_does_not_echo_query_string_and_is_localized(): void
    {
        config(['app.debug' => false]);
        config(['database.connections.mysql.host' => '127.0.0.1']);
        config(['database.connections.mysql.port' => '59999']);
        DB::purge('mysql');

        $this->getJson('/api/v1/health?token=example-sensitive-value', ['Accept-Language' => 'ar'])
            ->assertStatus(503)
            ->assertJsonPath('instance', '/api/v1/health')
            ->assertJsonPath('detail', 'تعذر التحقق من جاهزية إحدى خدمات النظام الأساسية.')
            ->assertHeader('Content-Language', 'ar');
    }
}
