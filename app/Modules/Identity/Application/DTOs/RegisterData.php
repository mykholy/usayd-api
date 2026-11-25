<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

final readonly class RegisterData
{
    public function __construct(
        public string $name,
        public string $email,
        #[\SensitiveParameter] public string $password,
        public ?string $phone,
        public string $locale,
        public string $deviceName,
        public string $ip,
        public bool $stateful,
    ) {}
}
