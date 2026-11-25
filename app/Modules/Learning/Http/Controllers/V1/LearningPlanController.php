<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers\V1;

use App\Models\LearningPlan;
use App\Modules\Learning\Application\Actions\OverrideLearningPlan;
use App\Modules\Learning\Application\Actions\UpdateLearningPlan;
use App\Modules\Learning\Application\Queries\ShowLearningPlan;
use App\Modules\Learning\Http\Requests\UpdateLearningPlanRequest;
use App\Modules\Learning\Http\Resources\LearningPlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LearningPlanController
{
    public function show(LearningPlan $learningPlan, ShowLearningPlan $query): LearningPlanResource
    {
        return new LearningPlanResource($query->execute($learningPlan));
    }

    public function update(UpdateLearningPlanRequest $request, LearningPlan $learningPlan, UpdateLearningPlan $action): LearningPlanResource
    {
        return new LearningPlanResource($action->execute($learningPlan, $request->toDto()));
    }

    public function override(Request $request, LearningPlan $learningPlan, OverrideLearningPlan $action): JsonResponse
    {
        $result = $action->execute($learningPlan, (string) $request->attributes->get('audit_purpose'), (string) $request->user()?->role);

        return response()->json([
            'data' => new LearningPlanResource($result->plan),
            'audit_event_id' => $result->auditEventId,
        ]);
    }
}
