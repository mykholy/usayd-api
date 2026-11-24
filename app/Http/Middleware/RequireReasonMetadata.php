<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireReasonMetadata
{
    /**
     * Enforce mandatory business reason metadata for privileged operations.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $reason = $request->header('X-Audit-Reason')
            ?? $request->input('reason')
            ?? $request->input('purpose');

        if (! $reason || ! is_string($reason) || trim($reason) === '') {
            throw ApiException::forbidden('A documented business purpose / reason metadata is required for this privileged action.');
        }

        $request->attributes->set('audit_purpose', trim($reason));

        return $next($request);
    }
}
