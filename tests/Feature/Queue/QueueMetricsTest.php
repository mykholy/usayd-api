<?php

namespace Tests\Feature\Queue;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
