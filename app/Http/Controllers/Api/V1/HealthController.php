<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * Perform application health and subsystem readiness checks.
     * Sanitizes public output to prevent system information disclosure.
     * Adheres to RFC 7807 for error representation upon failure.
     */
    public function check(Request $request): JsonResponse
    {
        $dbCheck = $this->checkDatabase();
        $redisCheck = $this->checkRedis();

        $isDbConnected = $dbCheck['status'] === 'connected';
        $isDebug = (bool) config('app.debug', false);

        if (! $isDbConnected) {
            $payload = [
                'type' => 'https://api.usayd.academy/errors/service-unavailable',
                'title' => 'Service Unavailable',
                'status' => 503,
                'detail' => 'One or more critical backend dependencies failed health verification.',
                'instance' => $request->getRequestUri(),
                'timestamp' => now()->toIso8601String(),
                'services' => [
                    'database' => $dbCheck['status'],
                    'redis' => $redisCheck['status'],
                ],
            ];

            if ($isDebug) {
                $payload['debug'] = [
                    'database_error' => $dbCheck['error'] ?? null,
                    'redis_error' => $redisCheck['error'] ?? null,
                ];
            }

            return response()->json($payload, 503, ['Content-Type' => 'application/problem+json']);
        }

        $redisRequired = config('cache.default') === 'redis' || config('queue.default') === 'redis';

        $overallStatus = 'healthy';
        if ($redisRequired && $redisCheck['status'] !== 'connected') {
            $overallStatus = 'degraded';
        }

        $payload = [
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'services' => [
                'database' => $dbCheck['status'],
                'redis' => $redisCheck['status'],
            ],
        ];

        if ($isDebug) {
            $payload['runtime'] = [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'environment' => app()->environment(),
            ];
            $payload['diagnostics'] = [
                'database_latency_ms' => $dbCheck['latency_ms'] ?? null,
                'redis_latency_ms' => $redisCheck['latency_ms'] ?? null,
            ];
        }

        return response()->json($payload, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Verify active MySQL 8.4 database connectivity.
     *
     * @return array<string, mixed>
     */
    protected function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->select('SELECT 1 as ping');
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'connected',
                'latency_ms' => $latencyMs,
            ];
        } catch (Throwable $e) {
            Log::error('Health probe database connectivity failure', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'unreachable',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify Redis connection according to cache/queue readiness policy.
     * Redis is optional unless explicitly configured as queue or cache store.
     *
     * @return array<string, mixed>
     */
    protected function checkRedis(): array
    {
        $redisRequired = config('cache.default') === 'redis' || config('queue.default') === 'redis';

        try {
            $start = microtime(true);
            Redis::connection()->ping();
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'connected',
                'latency_ms' => $latencyMs,
            ];
        } catch (Throwable $e) {
            Log::warning('Health probe Redis connectivity check failed', [
                'exception' => $e->getMessage(),
                'required' => $redisRequired,
            ]);

            return [
                'status' => $redisRequired ? 'unreachable' : 'unavailable',
                'error' => $e->getMessage(),
            ];
        }
    }
}
