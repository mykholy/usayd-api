<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Ports;

interface SessionAuthenticator
{
    public function establish(int $accountId): void;

    public function destroy(): void;
}
