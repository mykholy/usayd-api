<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Ports;

interface AuthenticationAudit
{
    public function record(string $action, string $subjectType, string $subjectId, string $purpose, string $ip, array $metadata = []): void;
}
