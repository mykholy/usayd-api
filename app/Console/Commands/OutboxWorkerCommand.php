<?php

namespace App\Console\Commands;

use App\Services\Outbox\TransactionalOutboxService;
use Illuminate\Console\Command;

class OutboxWorkerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usayd:outbox-worker
                            {--once : Execute a single batch polling iteration}
                            {--limit=50 : Maximum number of messages to process per batch}
                            {--queue=notifications : Target Redis queue for dispatched jobs}
                            {--max-attempts=3 : Maximum retries before moving to dead-letter}
                            {--sleep=3 : Seconds to pause between polling cycles in daemon mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Polls pending transactional outbox messages using MySQL 8.4 SKIP LOCKED and relays them to Redis queues';

    /**
     * Execute the console command.
     */
    public function handle(TransactionalOutboxService $outboxService): int
    {
        $once = (bool) $this->option('once');
        $limit = max(1, (int) $this->option('limit'));
        $queue = (string) $this->option('queue');
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $sleepSeconds = max(1, (int) $this->option('sleep'));

        $this->info("Usayd Academy — Transactional Outbox Worker started (Queue: {$queue}, Limit: {$limit}).");

        do {
            $processed = $outboxService->publishPending(
                limit: $limit,
                targetQueue: $queue,
                maxAttempts: $maxAttempts
            );

            if ($processed > 0) {
                $this->info('['.now()->toIso8601String()."] Dispatched {$processed} outbox message(s).");
            }

            if ($once) {
                break;
            }

            sleep($sleepSeconds);
        } while (true);

        return 0;
    }
}
