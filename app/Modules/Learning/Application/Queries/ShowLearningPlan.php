<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Queries;

use App\Models\LearningPlan;

final class ShowLearningPlan
{
    public function execute(LearningPlan $plan): LearningPlan
    {
        return $plan;
    }
}
