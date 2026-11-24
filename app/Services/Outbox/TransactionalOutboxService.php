<?php

namespace App\Services\Outbox;

use App\Jobs\DispatchOutboxNotificationJob;
use App\Models\AuditEvent;
use App\Models\OutboxMessage;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class TransactionalOutboxService
{
    /**
     * Record an outbox event.
     * When invoked inside an active DB transaction, commits atomically with domain entity changes.
     */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?array $headers = null,
        ?DateTimeInterface $scheduledAt = null
    ): OutboxMessage {
        $headers = $headers ?? [];
        if (! isset($headers['trace_id'])) {
            $headers['trace_id'] = request()?->header('X-Trace-Id') ?? (string) Str::uuid();
        }
        if (! isset($headers['created_at'])) {
            $headers['created_at'] = now()->toIso8601String();
        }

        // Deduplication check if idempotency_key is provided in headers
        if (isset($headers['idempotency_key'])) {
            $existing = OutboxMessage::query()
                ->where('aggregate_type', $aggregateType)
                ->where('aggregate_id', $aggregateId)
                ->where('event_type', $eventType)
                ->where('headers->idempotency_key', $headers['idempotency_key'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return OutboxMessage::create([
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
            'headers' => $headers,
            'status' => OutboxMessage::STATUS_PENDING,
            'attempts' => 0,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    /**
     * Execute domain mutation and outbox record inside an atomic database transaction.
     */
    public function recordInTransaction(
        callable $callback,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?array $headers = null,
        ?DateTimeInterface $scheduledAt = null
    ): mixed {
        return DB::transaction(function () use (
            $callback,
            $eventType,
            $aggregateType,
            $aggregateId,
            $payload,
            $headers,
            $scheduledAt
        ) {
            $result = $callback();
            $this->record($eventType, $aggregateType, $aggregateId, $payload, $headers, $scheduledAt);

            return $result;
        });
    }

    /**
     * Poll and publish due pending outbox messages using MySQL 8.4 SKIP LOCKED concurrency control.
     * Returns the number of successfully dispatched messages.
     */
    public function publishPending(
        int $limit = 50,
        string $targetQueue = 'notifications',
        int $maxAttempts = 3,
        ?callable $dispatcher = null
    ): int {
        $dispatchedCount = 0;

        DB::transaction(function () use (
            $limit,
            $targetQueue,
            $maxAttempts,
            $dispatcher,
            &$dispatchedCount
        ) {
            // Concurrency-safe row locking with MySQL 8.4 InnoDB SKIP LOCKED
            $messages = OutboxMessage::query()
                ->due()
                ->orderBy('created_at', 'asc')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            foreach ($messages as $message) {
                try {
                    if ($dispatcher !== null) {
                        $dispatcher($message);
                    } else {
                        // Dispatch async job onto target Redis partition
                        DispatchOutboxNotificationJob::dispatch(
                            outboxMessageId: $message->id,
                            eventType: $message->event_type,
                            aggregateType: $message->aggregate_type,
                            aggregateId: $message->aggregate_id,
                            payload: $message->payload,
                            headers: $message->headers,
                            targetQueue: $targetQueue
                        );
                    }

                    $message->markDispatched();
                    $dispatchedCount++;
                } catch (Throwable $e) {
                    $message->recordAttemptFailure($e->getMessage(), $maxAttempts);

                    if ($message->isDeadLetter()) {
                        AuditEvent::create([
                            'trace_id' => $message->headers['trace_id'] ?? (string) Str::uuid(),
                            'subject_type' => 'outbox_dead_letter',
                            'subject_id' => $message->id,
                            'action' => 'outbox_message_dead_lettered',
                            'purpose' => 'Outbox dispatch exhausted retry budget',
                            'metadata' => [
                                'event_type' => $message->event_type,
                                'aggregate_type' => $message->aggregate_type,
                                'aggregate_id' => $message->aggregate_id,
                                'attempts' => $message->attempts,
                                'error' => $e->getMessage(),
                            ],
                        ]);
                    }
                }
            }
        });

        return $dispatchedCount;
    }

    /**
     * Replay a specific dead-lettered message.
     */
    public function replay(string $outboxMessageId): OutboxMessage
    {
        $message = OutboxMessage::find($outboxMessageId);
        if (! $message) {
            throw new InvalidArgumentException("Outbox message with ID [{$outboxMessageId}] not found.");
        }

        $message->resetForReplay();

        AuditEvent::create([
            'trace_id' => (string) Str::uuid(),
            'subject_type' => 'outbox_replay',
            'subject_id' => $message->id,
            'action' => 'outbox_message_replayed',
            'purpose' => 'Administrative replay of dead-lettered outbox message',
            'metadata' => [
                'event_type' => $message->event_type,
                'aggregate_type' => $message->aggregate_type,
                'aggregate_id' => $message->aggregate_id,
            ],
        ]);

        return $message;
    }

    /**
     * Replay all dead-lettered outbox messages.
     */
    public function replayAllDeadLetter(): int
    {
        $messages = OutboxMessage::deadLetter()->get();
        $count = 0;

        foreach ($messages as $message) {
            $message->resetForReplay();
            $count++;
        }

        if ($count > 0) {
            AuditEvent::create([
                'trace_id' => (string) Str::uuid(),
                'subject_type' => 'outbox_replay',
                'subject_id' => 'batch',
                'action' => 'outbox_batch_replayed',
                'purpose' => "Administrative batch replay of [{$count}] dead-lettered outbox messages",
                'metadata' => ['replayed_count' => $count],
            ]);
        }

        return $count;
    }
}
