<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence;

use App\Models\LearningPlan;
use App\Modules\Learning\Application\DTOs\LearningPlanData;
use App\Modules\Learning\Application\DTOs\OverrideResult;
use App\Modules\Learning\Application\Ports\LearningPlanStore;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class EloquentLearningPlanStore implements LearningPlanStore
{
    public function update(LearningPlan $plan, LearningPlanData $data): LearningPlan
    {
        return DB::transaction(function () use ($plan, $data): LearningPlan {
            $record = LearningPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            Gate::authorize('update', $record);
            $record->update(['title' => $data->title, 'status' => $data->status]);

            AuditLogger::log(
                action: 'learning_plan.updated',
                subjectType: 'learning_plan',
                subjectId: $record->id,
                purpose: 'Regular plan modification',
                metadata: ['new_status' => $data->status]
            );

            return $record;
        });
    }

    public function override(LearningPlan $plan, string $purpose, string $actorRole): OverrideResult
    {
        return DB::transaction(function () use ($plan, $purpose, $actorRole): OverrideResult {
            $record = LearningPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            Gate::authorize('override', $record);
            $record->update(['status' => 'completed']);

            $event = AuditLogger::log(
                action: 'learning_plan.administrative_override',
                subjectType: 'learning_plan',
                subjectId: $record->id,
                purpose: $purpose,
                metadata: [
                    'actor_role' => $actorRole,
                    'override_action' => 'force_complete',
                ]
            );

            return new OverrideResult($record, $event->id);
        });
    }
}
