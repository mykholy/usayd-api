<?php

declare(strict_types=1);

namespace App\Platform\Observability\Application;

interface HealthDependencies
{
    public function database(): DependencyStatus;

    public function redis(bool $required): DependencyStatus;

    public function redisRequired(): bool;
}
