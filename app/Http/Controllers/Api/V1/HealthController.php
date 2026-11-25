<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Platform\Observability\Application\CheckHealth;
use App\Platform\Observability\Http\HealthResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HealthController extends Controller
{
    public function check(Request $request, CheckHealth $action, HealthResponse $response): JsonResponse
    {
        return $response->render($action->execute(), $request->getPathInfo());
    }
}
