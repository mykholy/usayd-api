<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

abstract class BaseQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     * Enforces exponential backoff: 5s, 15s, 60s.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 60];
    }

    /**
     * Handle a job failure after all retries are exhausted.
     * Emits a terminal dead-letter event to the audit ledger with sanitized error metadata.
     */
    public function failed(?Throwable $exception): void
    {
        $jobId = $this->job ? $this->job->getJobId() : (string) Str::uuid();
        $queueName = $this->queue ?: 'default';

        $sanitizedMessage = null;
        if ($exception) {
            $msg = $exception->getMessage();
            // Sanitize potential credentials/secrets in exception string
            $msg = preg_replace('/(?i)(password|secret|key|token|bearer)[=:\s]+[^\s,;&]+/i', '$1=[REDACTED]', $msg);
            $sanitizedMessage = substr((string) $msg, 0, 500);
        }

        AuditEvent::create([
            'trace_id' => request()?->header('X-Trace-Id') ?? (string) Str::uuid(),
            'subject_type' => 'queue_dead_letter',
            'subject_id' => (string) $jobId,
            'action' => 'job_failed_terminal',
            'purpose' => 'Dead-letter recording for queue job exhaustion',
            'metadata' => [
                'job_class' => static::class,
                'queue' => $queueName,
                'attempts' => $this->attempts(),
                'exception_class' => $exception ? get_class($exception) : null,
                'exception_message' => $sanitizedMessage,
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
