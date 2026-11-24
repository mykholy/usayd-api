<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'key',
    'user_id',
    'route',
    'request_hash',
    'response_code',
    'response_headers',
    'response_body',
    'status',
    'expires_at',
])]
class IdempotencyKey extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'response_code' => 'integer',
            'response_headers' => 'array',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
