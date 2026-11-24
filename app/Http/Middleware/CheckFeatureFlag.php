<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeatureFlag
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $flagKey, string $mode = '503'): Response
    {
        $user = $request->user();

        if (! FeatureFlagService::isEnabled($flagKey, $user)) {
            if ($mode === '404' || $mode === 'hidden') {
                throw ApiException::notFound('The requested resource was not found.');
            }

            throw new ApiException(
                status: 503,
                errorCode: 'FEATURE_DISABLED',
                detail: "The feature '{$flagKey}' is currently disabled or under maintenance.",
                title: 'Feature Disabled',
                errors: ['feature' => $flagKey],
                extensions: ['feature' => $flagKey]
            );
        }

        return $next($request);
    }
}
