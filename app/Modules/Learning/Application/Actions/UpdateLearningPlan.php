<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Actions;

use App\Models\LearningPlan;
use App\Modules\Learning\Application\DTOs\LearningPlanData;
use App\Modules\Learning\Application\Ports\LearningPlanStore;

final readonly class UpdateLearningPlan
{
    public function __construct(private LearningPlanStore $store) {}

    public function execute(LearningPlan $plan, LearningPlanData $data): LearningPlan
    {
        return $this->store->update($plan, $data);
    }
}
