<?php

declare(strict_types=1);

namespace App\Platform\Observability\Application;

final readonly class HealthReport
{
    public function __construct(
        public DependencyStatus $database,
        public DependencyStatus $redis,
        public bool $redisRequired,
    ) {}

    public function status(): string
    {
        if (! $this->database->connected()) {
            return 'unavailable';
        }

        return $this->redisRequired && ! $this->redis->connected() ? 'degraded' : 'healthy';
    }
}
