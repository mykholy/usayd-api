<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Queue\QueueMetricsService;
use Illuminate\Http\JsonResponse;

final class QueueMetricsController extends Controller
{
    public function metrics(QueueMetricsService $metrics): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $metrics->getMetrics(),
        ]);
    }
}
