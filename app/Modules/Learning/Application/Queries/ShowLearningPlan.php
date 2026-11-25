<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Queries;

use App\Models\LearningPlan;
use Illuminate\Support\Facades\Gate;

final class ShowLearningPlan
{
    public function execute(LearningPlan $plan): LearningPlan
    {
        Gate::authorize('view', $plan);

        return $plan;
    }
}
