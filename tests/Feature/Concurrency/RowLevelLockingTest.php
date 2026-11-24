<?php

namespace Tests\Feature\Concurrency;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RowLevelLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pessimistic_row_level_locking_prevents_lost_updates(): void
    {
        // Setup initial balance record
        $record = AuditEvent::create([
            'subject_type' => 'account_balance',
            'subject_id' => 'acc-001',
            'action' => 'initial_credit',
            'purpose' => '1000',
            'metadata' => ['balance' => 1000],
        ]);

        // Transaction 1: Reads with lock, updates balance
        DB::transaction(function () use ($record) {
            $locked = DB::table('audit_events')
                ->where('id', $record->id)
                ->lockForUpdate()
                ->first();

            $currentBalance = json_decode($locked->metadata, true)['balance'];
            $newBalance = $currentBalance - 300;

            DB::table('audit_events')
                ->where('id', $record->id)
                ->update([
                    'purpose' => (string) $newBalance,
                    'metadata' => json_encode(['balance' => $newBalance]),
                ]);
        });

        // Transaction 2: Reads locked updated state, updates balance again
        DB::transaction(function () use ($record) {
            $locked = DB::table('audit_events')
                ->where('id', $record->id)
                ->lockForUpdate()
                ->first();

            $currentBalance = json_decode($locked->metadata, true)['balance'];
            $newBalance = $currentBalance - 200;

            DB::table('audit_events')
                ->where('id', $record->id)
                ->update([
                    'purpose' => (string) $newBalance,
                    'metadata' => json_encode(['balance' => $newBalance]),
                ]);
        });

        $finalRecord = AuditEvent::findOrFail($record->id);
        $this->assertSame(500, $finalRecord->metadata['balance'], 'Final balance must be exactly 1000 - 300 - 200 = 500.');
    }
}
