<?php

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Admin\FeatureFlagController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\QueueMetricsController;
use App\Http\Controllers\Api\V1\StorageController;
use App\Models\AuditEvent;
use App\Platform\Observability\Http\Middleware\AuthorizeQueueMetrics;
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
    Route::get('/queue/metrics', [QueueMetricsController::class, 'metrics'])
        ->middleware(AuthorizeQueueMetrics::class)->name('api.v1.queue.metrics');

    // Public cryptographically verified signed download
    Route::get('/storage/download', [StorageController::class, 'download'])->name('api.v1.storage.download');

    // Authentication and Token Lifecycle
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->name('api.v1.auth.register');
        Route::post('/login', [AuthController::class, 'login'])->name('api.v1.auth.login');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('api.v1.auth.refresh');

        Route::middleware(['auth:sanctum', 'ability:access-api', 'account.active'])->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        });
    });

    // Profile contract compliant with OpenAPI /api/v1/me
    Route::middleware(['auth:sanctum', 'ability:access-api', 'account.active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.v1.me');

        // Storage Privacy & Signed Links
        Route::post('/storage/signed-url', [StorageController::class, 'generateSignedUrl'])
            ->name('api.v1.storage.signed-url');
    });

    require app_path('Modules/Learning/Http/Routes/api.php');

    // Administration: Feature Flags & Kill Switches
    Route::middleware(['auth:sanctum', 'ability:access-api', 'account.active', 'role:admin'])->prefix('admin/feature-flags')->group(function () {
        Route::get('/', [FeatureFlagController::class, 'index'])->name('api.v1.admin.feature-flags.index');
        Route::put('/{key}', [FeatureFlagController::class, 'update'])
            ->middleware('require.reason')
            ->name('api.v1.admin.feature-flags.update');
    });

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

        // Test harness endpoints for RFC 7807 error taxonomy and feature flag testing
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

            Route::middleware(['feature:feature_in_app_video'])->get('/feature-protected', function () {
                return response()->json(['status' => 'success', 'feature' => 'feature_in_app_video']);
            });

            Route::middleware(['feature:feature_in_app_video,404'])->get('/feature-hidden', function () {
                return response()->json(['status' => 'success', 'feature' => 'feature_in_app_video']);
            });
        });
    }
});

// Root /api/health convenience alias
Route::get('/health', [HealthController::class, 'check'])->name('api.health');
