<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use RuntimeException;

class SampleProcessingJob extends BaseQueueJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $taskName,
        public array $payload = [],
        public bool $shouldFail = false
    ) {
        // Default to 'default' partition unless specified
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException("Simulated job failure for task: {$this->taskName}");
        }

        AuditEvent::create([
            'subject_type' => 'sample_job',
            'subject_id' => $this->taskName,
            'action' => 'job_processed_successfully',
            'purpose' => 'Asynchronous background job execution',
            'metadata' => $this->payload,
        ]);
    }
}
