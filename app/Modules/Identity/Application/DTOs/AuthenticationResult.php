<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

final readonly class AuthenticationResult
{
    public function __construct(
        public ?Account $account = null,
        public array $tokens = [],
    ) {}
}
