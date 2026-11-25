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
                throw ApiException::notFound(__('errors.RESOURCE_NOT_FOUND.detail'));
            }

            throw new ApiException(
                status: 503,
                errorCode: 'FEATURE_DISABLED',
                detail: __('api.feature_disabled', ['feature' => $flagKey]),
                title: __('api.feature_disabled_title'),
                errors: ['feature' => $flagKey],
                extensions: ['feature' => $flagKey]
            );
        }

        return $next($request);
    }
}
