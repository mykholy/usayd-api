<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ResilientTransaction
{
    /**
     * MySQL error code for deadlock detected (1213).
     */
    public const ER_LOCK_DEADLOCK = 1213;

    /**
     * MySQL error code for lock wait timeout exceeded (1205).
     */
    public const ER_LOCK_WAIT_TIMEOUT = 1205;

    /**
     * Execute a database transaction with automatic retry on deadlocks and lock timeouts.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  int  $maxAttempts  Maximum number of transaction attempts (default: 3)
     * @param  int  $baseBackoffMs  Initial backoff delay in milliseconds (default: 10)
     * @return T
     *
     * @throws Throwable
     */
    public static function run(Closure $callback, int $maxAttempts = 3, int $baseBackoffMs = 10): mixed
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return DB::transaction($callback);
            } catch (Throwable $e) {
                if (self::isDeadlockOrTimeout($e) && $attempts < $maxAttempts) {
                    $sleepMs = $baseBackoffMs * (2 ** ($attempts - 1));
                    usleep($sleepMs * 1000);

                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Check if the exception represents a transient deadlock or lock timeout in MySQL.
     */
    public static function isDeadlockOrTimeout(Throwable $e): bool
    {
        if ($e instanceof DeadlockException) {
            return true;
        }

        if ($e instanceof QueryException) {
            $errorCode = $e->errorInfo[1] ?? null;
            if ($errorCode === self::ER_LOCK_DEADLOCK || $errorCode === self::ER_LOCK_WAIT_TIMEOUT) {
                return true;
            }
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout');
    }
}
