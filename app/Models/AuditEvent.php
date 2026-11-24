<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
     * Audit log is append-only. No updated_at column.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
