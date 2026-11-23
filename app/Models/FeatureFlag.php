<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'key',
    'name',
    'description',
    'is_enabled',
    'rules',
])]
class FeatureFlag extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'rules' => 'array',
        ];
    }
}
