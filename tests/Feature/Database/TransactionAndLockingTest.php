<?php

namespace Tests\Feature\Database;

use App\Models\IdempotencyKey;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransactionAndLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_commits_atomic_changes_successfully(): void
    {
        DB::transaction(function () {
            User::create([
                'name' => 'Committed User',
                'email' => 'committed@example.com',
                'password' => 'password123',
            ]);

            IdempotencyKey::create([
                'key' => 'tx-key-001',
                'route' => '/api/v1/checkout',
                'request_hash' => hash('sha256', 'payload-1'),
                'status' => 'completed',
                'expires_at' => now()->addMinutes(30),
            ]);
        });

        $this->assertDatabaseHas('users', ['email' => 'committed@example.com']);
        $this->assertDatabaseHas('idempotency_keys', ['key' => 'tx-key-001']);
    }

    public function test_transaction_rolls_back_atomic_changes_on_exception(): void
    {
        try {
            DB::transaction(function () {
                User::create([
                    'name' => 'Rolled Back User',
                    'email' => 'rollback@example.com',
                    'password' => 'password123',
                ]);

                throw new Exception('Simulated domain failure requiring rollback.');
            });
        } catch (Exception $e) {
            $this->assertSame('Simulated domain failure requiring rollback.', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
    }

    public function test_pessimistic_row_level_locking_with_innodb(): void
    {
        $user = User::create([
            'name' => 'Lock Target User',
            'email' => 'locked@example.com',
            'password' => 'password123',
        ]);

        DB::transaction(function () use ($user) {
            $lockedUser = DB::table('users')
                ->where('id', $user->id)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull($lockedUser);
            $this->assertSame('locked@example.com', $lockedUser->email);

            DB::table('users')
                ->where('id', $user->id)
                ->update(['status' => 'suspended']);
        });

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'suspended',
        ]);
    }
}
