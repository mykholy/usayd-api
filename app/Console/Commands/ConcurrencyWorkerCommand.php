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
    protected $signature = 'usayd:concurrency-worker {--id= : Target audit event ID} {--increments=1 : Number of atomic increments}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Worker process for concurrent row-locking and atomic increments';

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
                $row = DB::table('audit_events')
                    ->where('id', $targetId)
                    ->lockForUpdate()
                    ->first();

                if ($row) {
                    $meta = json_decode($row->metadata, true) ?: ['count' => 0];
                    $count = ((int) ($meta['count'] ?? 0)) + 1;

                    DB::table('audit_events')
                        ->where('id', $targetId)
                        ->update([
                            'purpose' => (string) $count,
                            'metadata' => json_encode(['count' => $count]),
                        ]);
                }
            });

            usleep(2000);
        }

        return 0;
    }
}
