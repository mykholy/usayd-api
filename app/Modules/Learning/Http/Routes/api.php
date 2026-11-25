<?php

declare(strict_types=1);

use App\Modules\Learning\Http\Controllers\V1\LearningPlanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'ability:access-api', 'account.active'])->prefix('learning-plans')->group(function (): void {
    Route::get('/{learningPlan}', [LearningPlanController::class, 'show'])->name('api.v1.learning-plans.show');
    Route::put('/{learningPlan}', [LearningPlanController::class, 'update'])->name('api.v1.learning-plans.update');
    Route::post('/{learningPlan}/override', [LearningPlanController::class, 'override'])
        ->middleware(['role:academic,admin', 'require.reason'])
        ->name('api.v1.learning-plans.override');
});
