<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

final readonly class RefreshData
{
    public function __construct(
        #[\SensitiveParameter] public string $refreshToken,
        public string $deviceName,
    ) {}
}
