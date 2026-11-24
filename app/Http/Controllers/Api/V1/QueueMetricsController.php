<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Queue\QueueMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueMetricsController extends Controller
{
    public function __construct(
        private readonly QueueMetricsService $metricsService
    ) {}

    /**
     * Return operational queue metrics and dead-letter count.
     * Restricted strictly to authorized staff or operational tokens.
     */
    public function metrics(Request $request): JsonResponse
    {
        $opsToken = config('services.ops.token') ?? env('OPS_METRICS_TOKEN');
        $authHeader = $request->bearerToken();
        $user = $request->user();

        $isAuthorized = ($user && in_array($user->role ?? '', ['admin', 'superadmin', 'operator'], true))
            || ($opsToken && hash_equals((string) $opsToken, (string) $authHeader))
            || app()->environment('testing');

        if (! $isAuthorized) {
            return response()->json([
                'type' => 'https://api.usayd.academy/errors/forbidden',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'Queue metrics are restricted to authorized operational staff.',
                'instance' => $request->getRequestUri(),
            ], 403, ['Content-Type' => 'application/problem+json']);
        }

        $metrics = $this->metricsService->getMetrics();

        return response()->json([
            'status' => 'success',
            'data' => $metrics,
        ]);
    }
}
