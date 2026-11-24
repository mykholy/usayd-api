<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'id',
    'aggregate_type',
    'aggregate_id',
    'event_type',
    'idempotency_key',
    'payload',
    'headers',
    'status',
    'attempts',
    'error_message',
    'scheduled_at',
    'locked_at',
    'processed_at',
])]
class OutboxMessage extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    /**
     * Backwards-compatible alias for published.
     */
    public const STATUS_DISPATCHED = self::STATUS_PUBLISHED;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'locked_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Scope query to pending/retryable messages eligible for immediate polling.
     *
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->where(function (Builder $sub) {
                $sub->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            });
    }

    /**
     * Scope query to stale in-processing messages for zombie recovery.
     *
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    public function scopeStaleProcessing(Builder $query, int $staleSeconds = 300): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING)
            ->where(function (Builder $sub) use ($staleSeconds) {
                $sub->where('locked_at', '<=', now()->subSeconds($staleSeconds))
                    ->orWhere(function (Builder $nested) use ($staleSeconds) {
                        $nested->whereNull('locked_at')
                            ->where('updated_at', '<=', now()->subSeconds($staleSeconds));
                    });
            });
    }

    /**
     * Scope query to dead-letter messages.
     *
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    public function scopeDeadLetter(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DEAD_LETTER);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDispatched(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function isDeadLetter(): bool
    {
        return $this->status === self::STATUS_DEAD_LETTER;
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'locked_at' => now(),
        ]);
    }

    public function markPublished(?DateTimeInterface $processedAt = null): void
    {
        $this->update([
            'status' => self::STATUS_PUBLISHED,
            'processed_at' => $processedAt ?? now(),
            'locked_at' => null,
            'error_message' => null,
        ]);
    }

    public function markDispatched(?DateTimeInterface $processedAt = null): void
    {
        $this->markPublished($processedAt);
    }

    public function markDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'processed_at' => now(),
            'locked_at' => null,
            'error_message' => null,
        ]);
    }

    public function recordAttemptFailure(string $errorMessage, int $maxAttempts = 3): void
    {
        $newAttempts = $this->attempts + 1;
        $isExhausted = $newAttempts >= $maxAttempts;

        // Exponential backoff delays: 5s, 15s, 60s
        $backoffs = [1 => 5, 2 => 15, 3 => 60];
        $backoffSeconds = $backoffs[$newAttempts] ?? 60;
        $nextRetryAt = $isExhausted ? null : now()->addSeconds($backoffSeconds);

        // Sanitize credentials/tokens from error message
        $sanitized = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/=]+/i', 'Bearer [REDACTED]', $errorMessage);
        $sanitized = preg_replace('/(?i)(password|secret|key|token|api_key)[:=\s]+[^\s,;&]+/i', '$1=[REDACTED]', (string) $sanitized);
        $cleanError = substr((string) $sanitized, 0, 1000);

        $this->update([
            'attempts' => $newAttempts,
            'error_message' => $cleanError,
            'scheduled_at' => $nextRetryAt,
            'locked_at' => null,
            'status' => $isExhausted ? self::STATUS_DEAD_LETTER : self::STATUS_FAILED,
        ]);
    }

    public function resetForReplay(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'error_message' => null,
            'processed_at' => null,
            'locked_at' => null,
            'scheduled_at' => null,
        ]);
    }
}
