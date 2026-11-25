<?php

declare(strict_types=1);

namespace App\Platform\Idempotency;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final readonly class DatabaseIdempotency
{
    public function __construct(private IdempotencyProblem $problems) {}

    /**
     * Only same-connection database mutations and transactional outbox writes belong here.
     * External effects and streaming/authentication responses are not replay-safe.
     */
    public function execute(IdempotentOperation $operation, Request $request, Closure $next): Response
    {
        try {
            return DB::transaction(function () use ($operation, $request, $next): Response {
                $identity = [
                    'key' => $operation->key,
                    'user_id' => $operation->principalId,
                    'route' => $operation->route,
                ];

                $inserted = DB::table('idempotency_keys')->insertOrIgnore($identity + [
                    'request_hash' => $operation->hash,
                    'status' => 'in_flight',
                    'expires_at' => now()->addHours(24),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $record = IdempotencyKey::query()->where($identity)->lockForUpdate()->firstOrFail();

                if ($inserted === 0) {
                    // A legacy in-flight reservation is ambiguous; never auto-execute it again.
                    if ($record->status === 'in_flight') {
                        return $this->problems->render($request, 'request-in-flight');
                    }

                    if ($record->status !== 'failed' && ! $record->expires_at?->isPast()) {
                        if ($record->request_hash !== $operation->hash) {
                            return $this->problems->render($request, 'payload-mismatch');
                        }

                        if ($record->status === 'completed') {
                            $headers = $record->response_headers ?? [];
                            $headers['Idempotent-Replayed'] = 'true';

                            return response()->json($record->response_body ?? [], $record->response_code ?: 200, $headers);
                        }

                        return $this->problems->render($request, 'request-in-flight');
                    }

                    $record->update([
                        'request_hash' => $operation->hash,
                        'status' => 'in_flight',
                        'response_code' => null,
                        'response_headers' => null,
                        'response_body' => null,
                        'expires_at' => now()->addHours(24),
                    ]);
                }

                $response = $next($request);
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    throw new RollbackResponse($response);
                }

                $snapshot = ResponseSnapshot::capture($response, (string) ($request->attributes->get('api_locale') ?? app()->getLocale()));

                $record->update([
                    'status' => 'completed',
                    'response_code' => $snapshot->status,
                    'response_headers' => $snapshot->headers,
                    'response_body' => $snapshot->body,
                ]);

                return $response;
            }, 3);
        } catch (RollbackResponse $rollback) {
            return $rollback->response;
        }
    }
}
