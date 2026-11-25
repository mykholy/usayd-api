<?php

declare(strict_types=1);

namespace App\Platform\Idempotency;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class IdempotencyProblem
{
    public function render(Request $request, string $kind): JsonResponse
    {
        [$status, $code, $translation] = match ($kind) {
            'invalid-key' => [400, 'IDEMPOTENCY_INVALID_KEY', 'invalid'],
            'payload-mismatch' => [422, 'IDEMPOTENCY_PAYLOAD_MISMATCH', 'mismatch'],
            default => [409, 'IDEMPOTENCY_IN_FLIGHT', 'inflight'],
        };

        return response()->json([
            'type' => 'https://errors.usayd.academy/idempotency/'.$kind,
            'title' => __("api.idempotency_{$translation}_title"),
            'status' => $status,
            'detail' => __("api.idempotency_{$translation}_detail"),
            'instance' => '/'.ltrim($request->path(), '/'),
            'code' => $code,
            'errorCode' => $code,
            'traceId' => (string) ($request->attributes->get('traceId') ?? Str::uuid()),
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
