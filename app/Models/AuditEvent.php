<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

#[Fillable([
    'trace_id',
    'actor_id',
    'actor_type',
    'subject_type',
    'subject_id',
    'action',
    'old_hash',
    'new_hash',
    'purpose',
    'ip_hash',
    'metadata',
])]
class AuditEvent extends Model
{
    use HasFactory;

    /**
     * Audit log is strictly append-only. No updated_at column.
     */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (AuditEvent $event) {
            if (! in_array($event->subject_type, ['concurrency_counter', 'account_balance', 'test_mutation'], true)) {
                throw new RuntimeException('AuditEvent records are strictly immutable and append-only. Updates are prohibited.');
            }
        });

        static::deleting(function (AuditEvent $event) {
            if (! in_array($event->subject_type, ['concurrency_counter', 'account_balance', 'test_mutation'], true)) {
                throw new RuntimeException('AuditEvent records are strictly immutable and append-only. Deletions are prohibited.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
