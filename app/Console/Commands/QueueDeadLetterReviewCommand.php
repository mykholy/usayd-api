<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueDeadLetterReviewCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usayd:queue-dead-letter 
                            {--action=list : Action to perform: list, inspect, retry, flush}
                            {--id= : Specific failed job ID or UUID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect, review, and replay failed dead-letter jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = (string) $this->option('action');
        $id = $this->option('id');

        return match ($action) {
            'list' => $this->listFailedJobs(),
            'inspect' => $this->inspectFailedJob($id),
            'retry' => $this->retryFailedJob($id),
            'flush' => $this->flushFailedJobs(),
            default => $this->invalidAction($action),
        };
    }

    private function listFailedJobs(): int
    {
        $jobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get(['id', 'uuid', 'queue', 'failed_at']);

        if ($jobs->isEmpty()) {
            $this->info('No dead-letter failed jobs found in failed_jobs table.');

            return 0;
        }

        $this->table(['ID', 'UUID', 'Queue', 'Failed At'], $jobs->map(fn ($j) => [
            $j->id,
            $j->uuid,
            $j->queue,
            $j->failed_at,
        ]));

        return 0;
    }

    private function inspectFailedJob(?string $id): int
    {
        if (! $id) {
            $this->error('Please specify --id=<ID|UUID> to inspect.');

            return 1;
        }

        $job = DB::table('failed_jobs')
            ->where('id', $id)
            ->orWhere('uuid', $id)
            ->first();

        if (! $job) {
            $this->error("Failed job [{$id}] not found.");

            return 1;
        }

        $this->info("=== Failed Job [{$job->id}] ===");
        $this->line("UUID: {$job->uuid}");
        $this->line("Queue: {$job->queue}");
        $this->line("Connection: {$job->connection}");
        $this->line("Failed At: {$job->failed_at}");
        $this->line("\nException:\n".substr($job->exception, 0, 1000).'...');

        return 0;
    }

    private function retryFailedJob(?string $id): int
    {
        if (! $id) {
            $this->error('Please specify --id=<ID|UUID> to retry, or "all" to retry all.');

            return 1;
        }

        $this->info("Retrying failed job [{$id}]...");
        $exitCode = Artisan::call('queue:retry', ['id' => [$id]]);

        $this->line(Artisan::output());

        return $exitCode;
    }

    private function flushFailedJobs(): int
    {
        if (! $this->confirm('Are you sure you want to flush all dead-letter failed jobs?')) {
            $this->line('Cancelled.');

            return 0;
        }

        Artisan::call('queue:flush');
        $this->info('All failed jobs have been flushed.');

        return 0;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}. Supported actions: list, inspect, retry, flush.");

        return 1;
    }
}
