<?php

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Admin\FeatureFlagController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\QueueMetricsController;
use App\Models\AuditEvent;
use App\Models\LearningPlan;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    // Authentication and Token Lifecycle
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->name('api.v1.auth.register');
        Route::post('/login', [AuthController::class, 'login'])->name('api.v1.auth.login');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('api.v1.auth.refresh');

        Route::middleware(['auth:sanctum', 'ability:access-api'])->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        });
    });

    // Profile contract compliant with OpenAPI /api/v1/me
    Route::middleware(['auth:sanctum', 'ability:access-api'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.v1.me');
    });

    // Domain Resources: Learning Plans (RBAC x Scope x Ownership Matrix)
    Route::middleware(['auth:sanctum', 'ability:access-api'])->prefix('learning-plans')->group(function () {
        Route::get('/{learningPlan}', function (LearningPlan $learningPlan) {
            if (! Gate::allows('view', $learningPlan)) {
                throw ApiException::forbidden('Access to this learning plan is restricted by ownership/assignment policy.');
            }

            return response()->json(['data' => $learningPlan]);
        })->name('api.v1.learning-plans.show');

        Route::put('/{learningPlan}', function (Request $request, LearningPlan $learningPlan) {
            if (! Gate::allows('update', $learningPlan)) {
                throw ApiException::forbidden('You are not authorized to update this learning plan.');
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'status' => 'required|string|in:active,completed,draft',
            ]);

            $learningPlan->update($validated);

            AuditLogger::log(
                action: 'learning_plan.updated',
                subjectType: 'learning_plan',
                subjectId: $learningPlan->id,
                purpose: 'Regular plan modification',
                metadata: ['new_status' => $validated['status']]
            );

            return response()->json(['data' => $learningPlan]);
        })->name('api.v1.learning-plans.update');

        Route::post('/{learningPlan}/override', function (Request $request, LearningPlan $learningPlan) {
            if (! Gate::allows('override', $learningPlan)) {
                throw ApiException::forbidden('Privileged override requires academic or administrator credentials.');
            }

            $purpose = (string) $request->attributes->get('audit_purpose');

            $learningPlan->update(['status' => 'completed']);

            $event = AuditLogger::log(
                action: 'learning_plan.administrative_override',
                subjectType: 'learning_plan',
                subjectId: $learningPlan->id,
                purpose: $purpose,
                metadata: [
                    'actor_role' => $request->user()?->role,
                    'override_action' => 'force_complete',
                ]
            );

            return response()->json([
                'data' => $learningPlan,
                'audit_event_id' => $event->id,
            ]);
        })->middleware(['role:academic,admin', 'require.reason'])->name('api.v1.learning-plans.override');
    });

    // Administration: Feature Flags & Kill Switches
    Route::middleware(['auth:sanctum', 'ability:access-api', 'role:admin'])->prefix('admin/feature-flags')->group(function () {
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
