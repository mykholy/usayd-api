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
    'payload',
    'headers',
    'status',
    'attempts',
    'error_message',
    'scheduled_at',
    'processed_at',
])]
class OutboxMessage extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Scope query to pending messages eligible for immediate dispatch.
     *
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(function (Builder $sub) {
                $sub->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
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

    public function isDispatched(): bool
    {
        return $this->status === self::STATUS_DISPATCHED;
    }

    public function isDeadLetter(): bool
    {
        return $this->status === self::STATUS_DEAD_LETTER;
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
        ]);
    }

    public function markDispatched(?DateTimeInterface $processedAt = null): void
    {
        $this->update([
            'status' => self::STATUS_DISPATCHED,
            'processed_at' => $processedAt ?? now(),
            'error_message' => null,
        ]);
    }

    public function recordAttemptFailure(string $errorMessage, int $maxAttempts = 3): void
    {
        $newAttempts = $this->attempts + 1;
        $isExhausted = $newAttempts >= $maxAttempts;

        $this->update([
            'attempts' => $newAttempts,
            'error_message' => substr($errorMessage, 0, 1000),
            'status' => $isExhausted ? self::STATUS_DEAD_LETTER : self::STATUS_PENDING,
        ]);
    }

    public function resetForReplay(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'error_message' => null,
            'processed_at' => null,
            'scheduled_at' => null,
        ]);
    }
}
