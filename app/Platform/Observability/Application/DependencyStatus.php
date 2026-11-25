<?php

declare(strict_types=1);

namespace App\Platform\Observability\Application;

final readonly class DependencyStatus
{
    public function __construct(
        public string $status,
        public ?float $latencyMs = null,
        public ?string $error = null,
    ) {}

    public function connected(): bool
    {
        return $this->status === 'connected';
    }
}
