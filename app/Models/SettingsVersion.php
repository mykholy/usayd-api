<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'namespace',
    'scope',
    'schema_version',
    'settings',
    'status',
    'checksum',
    'effective_at',
    'proposed_by',
    'approved_by',
])]
class SettingsVersion extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'effective_at' => 'datetime',
            'proposed_by' => 'integer',
            'approved_by' => 'integer',
        ];
    }
}
