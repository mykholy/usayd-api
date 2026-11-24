<?php

namespace Tests\Feature\Concurrency;

use App\Support\Database\ResilientTransaction;
use Illuminate\Database\DeadlockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

class DeadlockRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_resilient_transaction_retries_and_succeeds_after_transient_deadlock(): void
    {
        $attempts = 0;

        $result = ResilientTransaction::run(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                $pdoException = new PDOException('Deadlock found when trying to get lock; try restarting transaction', 1213);
                $pdoException->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

                throw new DeadlockException('Deadlock found when trying to get lock', 1213, $pdoException);
            }

            return 'transaction_success_after_retry';
        }, maxAttempts: 3, baseBackoffMs: 5);

        $this->assertSame(2, $attempts, 'Transaction should have succeeded on the second attempt.');
        $this->assertSame('transaction_success_after_retry', $result);
    }

    public function test_resilient_transaction_exhausts_retries_on_persistent_failure(): void
    {
        $attempts = 0;

        $this->expectException(DeadlockException::class);

        try {
            ResilientTransaction::run(function () use (&$attempts) {
                $attempts++;

                $pdoException = new PDOException('Deadlock found when trying to get lock; try restarting transaction', 1213);
                $pdoException->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

                throw new DeadlockException('Deadlock found when trying to get lock', 1213, $pdoException);
            }, maxAttempts: 3, baseBackoffMs: 5);
        } finally {
            $this->assertSame(3, $attempts, 'Transaction should have attempted exactly 3 times before failing.');
        }
    }
}
