<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use Illuminate\Support\Str;

class DispatchOutboxNotificationJob extends BaseQueueJob
{
    /**
     * Create a new outbox notification dispatch job.
     */
    public function __construct(
        public string $outboxMessageId,
        public string $eventType,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload = [],
        public ?array $headers = null,
        public string $targetQueue = 'notifications'
    ) {
        $this->onQueue($targetQueue);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Record audit event for notification delivery
        AuditEvent::create([
            'trace_id' => $this->headers['trace_id'] ?? (string) Str::uuid(),
            'subject_type' => 'outbox_notification',
            'subject_id' => $this->outboxMessageId,
            'action' => 'notification_dispatched',
            'purpose' => "Notification dispatch for event [{$this->eventType}] on [{$this->aggregateType}:{$this->aggregateId}]",
            'metadata' => [
                'event_type' => $this->eventType,
                'aggregate_type' => $this->aggregateType,
                'aggregate_id' => $this->aggregateId,
                'payload_summary' => array_keys($this->payload),
                'queue' => $this->queue,
            ],
        ]);
    }
}
