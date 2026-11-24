<?php

namespace Tests\Feature\Concurrency;

use App\Models\SettingsVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RowLevelLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pessimistic_row_level_locking_prevents_lost_updates(): void
    {
        // Setup initial balance record on mutable table
        $record = SettingsVersion::create([
            'namespace' => 'billing',
            'scope' => 'account_balance',
            'settings' => ['balance' => 1000],
            'checksum' => hash('sha256', '1000'),
            'status' => 'active',
        ]);

        // Transaction 1: Reads with lock, updates balance
        DB::transaction(function () use ($record) {
            $locked = DB::table('settings_versions')
                ->where('id', $record->id)
                ->lockForUpdate()
                ->first();

            $currentBalance = json_decode($locked->settings, true)['balance'];
            $newBalance = $currentBalance - 300;

            DB::table('settings_versions')
                ->where('id', $record->id)
                ->update([
                    'settings' => json_encode(['balance' => $newBalance]),
                    'checksum' => hash('sha256', (string) $newBalance),
                    'updated_at' => now(),
                ]);
        });

        // Transaction 2: Reads locked updated state, updates balance again
        DB::transaction(function () use ($record) {
            $locked = DB::table('settings_versions')
                ->where('id', $record->id)
                ->lockForUpdate()
                ->first();

            $currentBalance = json_decode($locked->settings, true)['balance'];
            $newBalance = $currentBalance - 200;

            DB::table('settings_versions')
                ->where('id', $record->id)
                ->update([
                    'settings' => json_encode(['balance' => $newBalance]),
                    'checksum' => hash('sha256', (string) $newBalance),
                    'updated_at' => now(),
                ]);
        });

        $finalRecord = SettingsVersion::findOrFail($record->id);
        $this->assertSame(500, $finalRecord->settings['balance'], 'Final balance must be exactly 1000 - 300 - 200 = 500.');
    }
}
