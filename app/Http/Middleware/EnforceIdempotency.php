<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnforceIdempotency
{
    /**
     * Mutating HTTP methods subject to idempotency checks.
     */
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Sensitive headers that must never be cached or replayed.
     */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'set-cookie',
        'cookie',
        'x-auth-token',
        'x-xsrf-token',
        'date',
        'server',
        'x-powered-by',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Non-mutating methods bypass idempotency
        if (! in_array($request->method(), self::MUTATING_METHODS, true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        // If no Idempotency-Key header is supplied, proceed normally
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $next($request);
        }

        // Validate key format (1 to 255 printable ASCII characters without whitespace)
        if (! is_string($idempotencyKey) || strlen($idempotencyKey) > 255 || ! preg_match('/^[\x21-\x7E]+$/', $idempotencyKey)) {
            return $this->problemResponse(
                type: 'https://errors.usayd.academy/idempotency/invalid-key',
                title: 'Invalid Idempotency Key',
                status: 400,
                detail: 'The Idempotency-Key header must be between 1 and 255 printable ASCII characters without whitespace.'
            );
        }

        // Principal scoping: 0 indicates public/unauthenticated request
        $userId = (int) ($request->user()?->id ?? 0);
        $route = $request->path();

        // Deterministic hash scoped strictly by principal, method, route, and content
        $requestHash = hash('sha256', $userId.'|'.$request->method().'|'.$route.'|'.$request->getContent());

        // Atomic lookup and reservation inside transaction
        $existingRecord = null;
        $inFlightResponse = null;

        DB::transaction(function () use ($idempotencyKey, $userId, $route, $requestHash, &$existingRecord, &$inFlightResponse) {
            $existingRecord = IdempotencyKey::where('user_id', $userId)
                ->where('route', $route)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingRecord) {
                // If existing key is expired, remove it to permit fresh execution
                if ($existingRecord->expires_at && $existingRecord->expires_at->isPast()) {
                    $existingRecord->delete();
                    $existingRecord = null;
                } elseif ($existingRecord->status === 'failed') {
                    // Previous execution failed: remove failed record to permit clean retry reservation
                    $existingRecord->delete();
                    $existingRecord = null;
                } elseif ($existingRecord->request_hash !== $requestHash) {
                    // Different payload/user with same key: reject with 422
                    $inFlightResponse = $this->problemResponse(
                        type: 'https://errors.usayd.academy/idempotency/payload-mismatch',
                        title: 'Idempotency Payload Mismatch',
                        status: 422,
                        detail: 'This Idempotency-Key was previously used with a different request method, URL, or payload.'
                    );

                    return;
                } elseif ($existingRecord->status === 'in_flight') {
                    // Currently being processed: return 409 Conflict
                    $inFlightResponse = $this->problemResponse(
                        type: 'https://errors.usayd.academy/idempotency/request-in-flight',
                        title: 'Concurrent Request In Flight',
                        status: 409,
                        detail: 'A request with this Idempotency-Key is currently being processed. Please retry after completion.'
                    );

                    return;
                }
            }

            if (! $existingRecord && ! $inFlightResponse) {
                // Reserve idempotency key in-flight
                IdempotencyKey::create([
                    'key' => $idempotencyKey,
                    'user_id' => $userId,
                    'route' => $route,
                    'request_hash' => $requestHash,
                    'status' => 'in_flight',
                    'expires_at' => now()->addHours(24),
                ]);
            }
        });

        // Return early if mismatch or conflict detected
        if ($inFlightResponse) {
            return $inFlightResponse;
        }

        // If completed record exists, return cached replay
        if ($existingRecord && $existingRecord->status === 'completed') {
            $cachedBody = $existingRecord->response_body ?? [];
            $headers = $existingRecord->response_headers ?? [];
            $headers['Idempotent-Replayed'] = 'true';

            return response()->json($cachedBody, $existingRecord->response_code ?: 200, $headers);
        }

        // Execute request
        try {
            $response = $next($request);

            // Record completed response
            $content = $response->getContent();
            $decodedBody = json_decode($content, true) ?: ['raw' => $content];

            // Never persist auth tokens, passwords, or session secrets
            if (is_array($decodedBody)) {
                $decodedBody = $this->sanitizeResponseBody($decodedBody);
            }

            $headersToCache = [];
            foreach ($response->headers->allPreserveCase() as $headerName => $values) {
                $lower = strtolower($headerName);
                if (! in_array($lower, self::SENSITIVE_HEADERS, true)) {
                    $headersToCache[$headerName] = implode(', ', $values);
                }
            }

            IdempotencyKey::where('user_id', $userId)
                ->where('route', $route)
                ->where('key', $idempotencyKey)
                ->update([
                    'status' => 'completed',
                    'response_code' => $response->getStatusCode(),
                    'response_headers' => $headersToCache,
                    'response_body' => $decodedBody,
                ]);

            return $response;
        } catch (Throwable $e) {
            // On failure, mark key failed so client can safely retry
            IdempotencyKey::where('user_id', $userId)
                ->where('route', $route)
                ->where('key', $idempotencyKey)
                ->update([
                    'status' => 'failed',
                ]);

            throw $e;
        }
    }

    /**
     * Sanitize cached response body to ensure secrets are never stored.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function sanitizeResponseBody(array $body): array
    {
        $sensitiveKeys = ['token', 'access_token', 'refresh_token', 'password', 'secret', 'client_secret'];
        foreach ($body as $k => $v) {
            if (in_array(strtolower((string) $k), $sensitiveKeys, true)) {
                $body[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $body[$k] = $this->sanitizeResponseBody($v);
            }
        }

        return $body;
    }

    /**
     * Render an RFC 7807 compliant problem details response.
     */
    private function problemResponse(string $type, string $title, int $status, string $detail): JsonResponse
    {
        return response()->json([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => request()->path(),
        ], $status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
