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
     * Audit log is append-only at the application layer. No updated_at column.
     */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('AuditEvent Eloquent records are append-only. Updates via Eloquent are prohibited.');
        });

        static::deleting(function () {
            throw new RuntimeException('AuditEvent Eloquent records are append-only. Deletions via Eloquent are prohibited.');
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
