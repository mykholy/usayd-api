<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Authentication;

use App\Modules\Identity\Application\Ports\LoginAttempts;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class LaravelLoginAttempts implements LoginAttempts
{
    public function retryAfter(string $email, string $ip): ?int
    {
        $key = $this->key($email, $ip);

        return RateLimiter::tooManyAttempts($key, 5) ? RateLimiter::availableIn($key) : null;
    }

    public function fail(string $email, string $ip): void
    {
        RateLimiter::hit($this->key($email, $ip), 60);
    }

    public function clear(string $email, string $ip): void
    {
        RateLimiter::clear($this->key($email, $ip));
    }

    private function key(string $email, string $ip): string
    {
        return 'login:'.Str::lower($email).'|'.$ip;
    }
}
