<?php

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\QueueMetricsController;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| API Routes — Usayd Academy
|--------------------------------------------------------------------------
| Authoritative API routes prefix: /api/v1
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', [HealthController::class, 'check'])->name('api.v1.health');
    Route::get('/queue/metrics', [QueueMetricsController::class, 'metrics'])->name('api.v1.queue.metrics');

    // Test harness endpoints restricted strictly to local and testing environments
    if (app()->environment('local', 'testing')) {
        Route::post('/test/idempotent-mutation', function (Request $request) {
            $validated = $request->validate([
                'action' => 'required|string',
                'amount' => 'required|integer|min:1',
            ]);

            $event = AuditEvent::create([
                'subject_type' => 'test_mutation',
                'subject_id' => (string) Str::uuid(),
                'action' => $validated['action'],
                'purpose' => 'Idempotency test execution',
                'metadata' => [
                    'amount' => $validated['amount'],
                    'client_ip' => $request->ip(),
                ],
            ]);

            return response()->json([
                'status' => 'success',
                'mutation_id' => $event->id,
                'action' => $validated['action'],
                'amount' => $validated['amount'],
            ], 201);
        })->middleware('idempotent')->name('api.v1.test.idempotent');

        // Test harness endpoints for RFC 7807 error taxonomy testing
        Route::prefix('test/errors')->group(function () {
            Route::get('/unauthorized', function () {
                throw ApiException::unauthorized();
            });
            Route::get('/forbidden', function () {
                throw ApiException::forbidden();
            });
            Route::get('/not-found', function () {
                throw ApiException::notFound();
            });
            Route::get('/conflict', function () {
                throw ApiException::conflict();
            });
            Route::get('/rate-limited', function () {
                throw ApiException::rateLimited(retryAfter: 120);
            });
            Route::post('/validation', function (Request $request) {
                $request->validate([
                    'email' => 'required|email',
                    'name' => 'required|string|min:3',
                ]);

                return response()->json(['status' => 'ok']);
            });
            Route::get('/server-error', function () {
                throw new RuntimeException('Database query timeout error with sensitive sql info: SELECT * FROM users WHERE pass=123');
            });
            Route::get('/custom-api-exception', function () {
                throw new ApiException(
                    status: 400,
                    errorCode: 'INVALID_RESERVATION_STATE',
                    detail: 'The slot is no longer available.',
                    title: 'Invalid Slot State',
                    errors: ['slot_id' => ['Slot #42 has already expired']],
                    extensions: ['availableUntil' => '2026-11-24T12:00:00Z']
                );
            });
        });
    }
});

// Root /api/health convenience alias
Route::get('/health', [HealthController::class, 'check'])->name('api.health');
