<?php

declare(strict_types=1);

namespace App\Platform\Observability\Http;

use App\Platform\Observability\Application\HealthReport;
use Illuminate\Http\JsonResponse;

final class HealthResponse
{
    public function render(HealthReport $report, string $path): JsonResponse
    {
        $debug = config('app.debug') && app()->environment('local', 'testing');
        $services = [
            'database' => $report->database->status,
            'redis' => $report->redis->status,
        ];

        if ($report->status() === 'unavailable') {
            $payload = [
                'type' => 'https://api.usayd.academy/errors/service-unavailable',
                'title' => __('errors.SERVICE_UNAVAILABLE.title'),
                'status' => 503,
                'detail' => __('api.health_unavailable'),
                'instance' => $path,
                'timestamp' => now()->toIso8601String(),
                'services' => $services,
            ];

            if ($debug) {
                $payload['debug'] = [
                    'database_error' => $report->database->error,
                    'redis_error' => $report->redis->error,
                ];
            }

            return response()->json($payload, 503, ['Content-Type' => 'application/problem+json']);
        }

        $payload = [
            'status' => $report->status(),
            'timestamp' => now()->toIso8601String(),
            'services' => $services,
        ];

        if ($debug) {
            $payload['runtime'] = [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'environment' => app()->environment(),
            ];
            $payload['diagnostics'] = [
                'database_latency_ms' => $report->database->latencyMs,
                'redis_latency_ms' => $report->redis->latencyMs,
            ];
        }

        return response()->json($payload, 200, ['Content-Type' => 'application/json']);
    }
}
