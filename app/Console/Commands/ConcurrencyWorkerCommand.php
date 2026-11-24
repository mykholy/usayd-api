<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConcurrencyWorkerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usayd:concurrency-worker {--id= : Target settings version ID} {--increments=1 : Number of atomic increments}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Worker process for concurrent row-locking and atomic increments on mutable configuration tables';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $targetId = (int) $this->option('id');
        $increments = (int) $this->option('increments');

        if (! $targetId) {
            $this->error('Target ID is required.');

            return 1;
        }

        for ($i = 0; $i < $increments; $i++) {
            DB::transaction(function () use ($targetId) {
                $row = DB::table('settings_versions')
                    ->where('id', $targetId)
                    ->lockForUpdate()
                    ->first();

                if ($row) {
                    $settings = json_decode($row->settings, true) ?: ['count' => 0];
                    $count = ((int) ($settings['count'] ?? 0)) + 1;

                    DB::table('settings_versions')
                        ->where('id', $targetId)
                        ->update([
                            'settings' => json_encode(['count' => $count]),
                            'checksum' => hash('sha256', (string) $count),
                            'updated_at' => now(),
                        ]);
                }
            });

            usleep(2000);
        }

        return 0;
    }
}
