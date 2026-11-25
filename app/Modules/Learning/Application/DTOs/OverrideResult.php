<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\DTOs;

use App\Models\LearningPlan;

final readonly class OverrideResult
{
    public function __construct(
        public LearningPlan $plan,
        public int|string $auditEventId,
    ) {}
}
