<?php

namespace Tests\Feature\Queue;

use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_metrics_endpoint_returns_json_operational_status(): void
    {
        // Seed a failed job
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'notifications',
            'payload' => json_encode(['job' => 'SendSmsJob']),
            'exception' => "TwilioConnectionException: Gateway Timeout\nat /app/Jobs/SendSmsJob.php:40",
            'failed_at' => now(),
        ]);

        config(['services.ops.token' => 'test-ops-token']);
        $response = $this->withToken('test-ops-token')->getJson('/api/v1/queue/metrics');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'status',
                'default_connection',
                'partitions',
                'failed_jobs' => [
                    'total',
                    'recent',
                ],
                'timestamp',
            ],
        ]);

        $this->assertSame(1, $response->json('data.failed_jobs.total'));
        $this->assertStringNotContainsString('TwilioConnectionException', (string) $response->getContent());
        $this->assertArrayHasKey('high', $response->json('data.partitions'));
        $this->assertArrayHasKey('default', $response->json('data.partitions'));
        $this->assertArrayHasKey('notifications', $response->json('data.partitions'));
        $this->assertArrayHasKey('reports', $response->json('data.partitions'));
    }

    public function test_queue_metrics_forbidden_without_authorization_even_in_testing(): void
    {
        config(['services.ops.token' => null]);

        $response = $this->getJson('/api/v1/queue/metrics');
        $response->assertStatus(403)
            ->assertJsonPath('status', 403);
    }

    public function test_queue_metrics_allowed_with_valid_ops_bearer_token(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.ops.token' => 'ops-secret-token-12345']);

        $response = $this->withHeader('Authorization', 'Bearer ops-secret-token-12345')
            ->getJson('/api/v1/queue/metrics');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_admin_access_token_is_allowed_but_refresh_token_is_not(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tokens = app(TokenService::class)->issueTokens($admin);
        config(['services.ops.token' => null]);

        $this->withToken($tokens['access_token'])->getJson('/api/v1/queue/metrics')
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['refresh_token'])->getJson('/api/v1/queue/metrics')
            ->assertStatus(403);
    }

    public function test_suspended_admin_and_learner_are_denied(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $learner = User::factory()->create(['role' => 'learner']);
        $adminTokens = app(TokenService::class)->issueTokens($admin);
        $learnerTokens = app(TokenService::class)->issueTokens($learner);
        $admin->update(['status' => 'suspended']);
        config(['services.ops.token' => null]);

        $this->withToken($adminTokens['access_token'])->getJson('/api/v1/queue/metrics')
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->withToken($learnerTokens['access_token'])->getJson('/api/v1/queue/metrics')
            ->assertStatus(403);
    }

    public function test_queue_connection_failure_is_reported_as_degraded(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('Simulated outage'));
        config(['services.ops.token' => 'test-ops-token']);

        $this->withToken('test-ops-token')->getJson('/api/v1/queue/metrics')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonCount(4, 'data.unavailable_partitions');
    }
}
