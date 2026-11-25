<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

final readonly class LoginData
{
    public function __construct(
        public string $email,
        #[\SensitiveParameter] public string $password,
        public string $deviceName,
        public string $ip,
        public bool $stateful,
    ) {}
}
