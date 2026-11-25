<?php

declare(strict_types=1);

namespace App\Platform\Observability\Infrastructure;

use App\Platform\Observability\Application\DependencyStatus;
use App\Platform\Observability\Application\HealthDependencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class LaravelHealthDependencies implements HealthDependencies
{
    public function database(): DependencyStatus
    {
        return $this->probe('database', fn () => DB::connection()->select('SELECT 1 as ping'), true);
    }

    public function redis(bool $required): DependencyStatus
    {
        return $this->probe('redis', fn () => Redis::connection()->ping(), $required);
    }

    public function redisRequired(): bool
    {
        return config('cache.default') === 'redis' || config('queue.default') === 'redis';
    }

    private function probe(string $name, callable $check, bool $required): DependencyStatus
    {
        try {
            $start = microtime(true);
            $check();

            return new DependencyStatus('connected', round((microtime(true) - $start) * 1000, 2));
        } catch (Throwable $exception) {
            // Connection strings, credentials, query text and stack traces are not logged here.
            Log::warning('Health dependency probe failed', [
                'dependency' => $name,
                'required' => $required,
                'exception_class' => $exception::class,
            ]);

            return new DependencyStatus($required ? 'unreachable' : 'unavailable', error: $exception::class);
        }
    }
}
