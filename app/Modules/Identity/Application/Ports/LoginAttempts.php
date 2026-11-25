<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Ports;

interface LoginAttempts
{
    public function retryAfter(string $email, string $ip): ?int;

    public function fail(string $email, string $ip): void;

    public function clear(string $email, string $ip): void;
}
