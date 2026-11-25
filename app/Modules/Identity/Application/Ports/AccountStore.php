<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Ports;

use App\Modules\Identity\Application\DTOs\Account;
use App\Modules\Identity\Application\DTOs\RegisterData;

interface AccountStore
{
    public function authenticate(string $email, #[\SensitiveParameter] string $password): ?Account;

    public function register(RegisterData $data): Account;
}
