<?php

declare(strict_types=1);

namespace App\Platform\Idempotency;

final readonly class IdempotentOperation
{
    public function __construct(
        public string $key,
        public int $principalId,
        public string $route,
        public string $hash,
    ) {}
}
