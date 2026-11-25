<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Actions;

use App\Models\LearningPlan;
use App\Modules\Learning\Application\DTOs\OverrideResult;
use App\Modules\Learning\Application\Ports\LearningPlanStore;

final readonly class OverrideLearningPlan
{
    public function __construct(private LearningPlanStore $store) {}

    public function execute(LearningPlan $plan, string $purpose, string $actorRole): OverrideResult
    {
        return $this->store->override($plan, $purpose, $actorRole);
    }
}
