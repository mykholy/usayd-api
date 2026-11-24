<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
}
