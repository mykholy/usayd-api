<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Ports;

use App\Models\LearningPlan;
use App\Modules\Learning\Application\DTOs\LearningPlanData;
use App\Modules\Learning\Application\DTOs\OverrideResult;

interface LearningPlanStore
{
    public function update(LearningPlan $plan, LearningPlanData $data): LearningPlan;

    public function override(LearningPlan $plan, string $purpose, string $actorRole): OverrideResult;
}
