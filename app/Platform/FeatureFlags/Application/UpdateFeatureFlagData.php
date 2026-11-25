<?php

declare(strict_types=1);

namespace App\Platform\FeatureFlags\Application;

final readonly class UpdateFeatureFlagData
{
    public function __construct(
        public bool $enabled,
        public bool $replaceRules,
        public ?array $rules,
        public ?string $name,
        public ?string $description,
        public string $purpose,
    ) {}
}
