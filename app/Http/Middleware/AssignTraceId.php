<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignTraceId
{
    /**
     * Handle an incoming request and ensure a validated, normalized traceId is present.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = null;

        // 1. Try parsing W3C traceparent header (format: 00-<32hex>-<16hex>-<2hex>)
        $traceparent = $request->header('traceparent');
        if (is_string($traceparent) && preg_match('/^00-([0-9a-fA-F]{32})-[0-9a-fA-F]{16}-[0-9a-fA-F]{2}$/', trim($traceparent), $matches)) {
            $traceId = strtolower($matches[1]);
        }

        // 2. Try X-Request-ID or X-Trace-ID if valid UUID or safe correlation ID (16-64 chars)
        if (! $traceId) {
            $incomingId = $request->header('X-Request-ID') ?? $request->header('X-Trace-ID');
            if (is_string($incomingId) && preg_match('/^[a-zA-Z0-9_\-]{16,64}$/', trim($incomingId))) {
                $traceId = trim($incomingId);
            }
        }

        // 3. Fallback to clean UUIDv4
        if (! $traceId) {
            $traceId = (string) Str::uuid();
        }

        $request->attributes->set('traceId', $traceId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-ID', $traceId);
        $response->headers->set('X-Trace-ID', $traceId);

        return $response;
    }
}
