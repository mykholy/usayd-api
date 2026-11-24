<?php

use App\Http\Controllers\Api\V1\HealthController;
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
    }
});

// Root /api/health convenience alias
Route::get('/health', [HealthController::class, 'check'])->name('api.health');
