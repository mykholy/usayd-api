<?php

namespace App\Console\Commands;

use App\Services\Outbox\TransactionalOutboxService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class OutboxReplayCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usayd:outbox-replay
                            {id? : Specific outbox message UUID to replay}
                            {--all-dead-letter : Replay all messages currently in dead-letter status}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replays failed or dead-lettered transactional outbox messages';

    /**
     * Execute the console command.
     */
    public function handle(TransactionalOutboxService $outboxService): int
    {
        $id = $this->argument('id');
        $allDeadLetter = (bool) $this->option('all-dead-letter');

        if (! $id && ! $allDeadLetter) {
            $this->error('Must specify a message UUID or pass --all-dead-letter.');

            return 1;
        }

        if ($allDeadLetter) {
            $count = $outboxService->replayAllDeadLetter();
            $this->info("Replayed {$count} dead-lettered message(s) back to pending.");

            return 0;
        }

        try {
            $message = $outboxService->replay($id);
            $this->info("Outbox message [{$message->id}] successfully reset to pending for replay.");

            return 0;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }
}
