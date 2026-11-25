<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Ports\IdentityTransaction;
use Illuminate\Support\Facades\DB;

final class DatabaseIdentityTransaction implements IdentityTransaction
{
    public function run(callable $operation): mixed
    {
        return DB::transaction($operation);
    }
}
