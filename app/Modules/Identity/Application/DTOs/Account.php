<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

final readonly class Account
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $locale,
        public string $role,
        public string $status,
        public string $timezone,
    ) {}
}
