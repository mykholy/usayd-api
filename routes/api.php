<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Usayd Academy
|--------------------------------------------------------------------------
| Authoritative API routes prefix: /api/v1
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', [HealthController::class, 'check'])->name('api.v1.health');
});

// Root /api/health convenience alias
Route::get('/health', [HealthController::class, 'check'])->name('api.health');
