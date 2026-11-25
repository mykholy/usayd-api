<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DispatchOutboxNotificationJob extends BaseQueueJob
{
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

    public function handle(): void
    {
        DB::transaction(function (): void {
            $outbox = OutboxMessage::query()->lockForUpdate()->find($this->outboxMessageId);

            if (! $outbox || (! $outbox->isPublished() && ! $outbox->isProcessing())) {
                return;
            }

            // Internal consumer acknowledgment only; no external provider delivery is implemented.
            // Read the canonical event from the database, not the serialized job payload.
            $outbox->markDelivered();

            AuditEvent::create([
                'trace_id' => $outbox->headers['trace_id'] ?? (string) Str::uuid(),
                'subject_type' => 'outbox_notification',
                'subject_id' => $outbox->id,
                'action' => 'notification_delivered',
                'purpose' => 'Internal notification consumer acknowledgment (no external delivery confirmation)',
                'metadata' => [
                    'event_type' => $outbox->event_type,
                    'aggregate_type' => $outbox->aggregate_type,
                    'aggregate_id' => $outbox->aggregate_id,
                    'payload_summary' => array_keys($outbox->payload),
                    'queue' => $this->queue,
                    'delivered_at' => now()->toIso8601String(),
                    'delivery_scope' => 'internal_scaffold',
                ],
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        parent::failed($exception);

        DB::transaction(function () use ($exception): void {
            $outbox = OutboxMessage::query()->lockForUpdate()->find($this->outboxMessageId);
            if ($outbox && ($outbox->isPublished() || $outbox->isProcessing())) {
                $outbox->recordAttemptFailure($exception ? $exception::class : 'Consumer execution failure', $this->tries);
            }
        });
    }
}
