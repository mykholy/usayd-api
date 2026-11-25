<?php

declare(strict_types=1);

namespace App\Platform\Observability\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeQueueMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('services.ops.token');
        $providedToken = $request->bearerToken();

        if (is_string($configuredToken) && $configuredToken !== ''
            && is_string($providedToken) && hash_equals($configuredToken, $providedToken)) {
            return $next($request);
        }

        $actor = Auth::guard('sanctum')->user();

        if (! $actor instanceof User || $actor->fresh()?->status !== 'active'
            || ! $actor->isAdmin() || ! $actor->tokenCan('access-api')) {
            throw ApiException::forbidden(__('api.queue_forbidden'));
        }

        return $next($request);
    }
}
