<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Platform\FeatureFlags\Application\ListFeatureFlags;
use App\Platform\FeatureFlags\Application\UpdateFeatureFlag;
use App\Platform\FeatureFlags\Http\Requests\UpdateFeatureFlagRequest;
use Illuminate\Http\JsonResponse;

final class FeatureFlagController extends Controller
{
    public function index(ListFeatureFlags $query): JsonResponse
    {
        return response()->json(['data' => $query->execute()]);
    }

    public function update(UpdateFeatureFlagRequest $request, string $key, UpdateFeatureFlag $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($key, $request->toDto()),
            'message' => __('api.feature_flag_updated'),
        ]);
    }
}
