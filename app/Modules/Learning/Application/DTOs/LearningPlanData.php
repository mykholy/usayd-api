<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\DTOs;

final readonly class LearningPlanData
{
    public function __construct(
        public string $title,
        public string $status,
    ) {}
}
