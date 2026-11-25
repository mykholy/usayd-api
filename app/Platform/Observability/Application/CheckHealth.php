<?php

declare(strict_types=1);

namespace App\Platform\Observability\Application;

final readonly class CheckHealth
{
    public function __construct(private HealthDependencies $dependencies) {}

    public function execute(): HealthReport
    {
        $redisRequired = $this->dependencies->redisRequired();

        return new HealthReport(
            $this->dependencies->database(),
            $this->dependencies->redis($redisRequired),
            $redisRequired,
        );
    }
}
