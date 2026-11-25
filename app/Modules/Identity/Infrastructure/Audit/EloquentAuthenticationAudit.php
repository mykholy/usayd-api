<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Audit;

use App\Modules\Identity\Application\Ports\AuthenticationAudit;
use App\Services\Audit\AuditLogger;

final class EloquentAuthenticationAudit implements AuthenticationAudit
{
    public function record(string $action, string $subjectType, string $subjectId, string $purpose, string $ip, array $metadata = []): void
    {
        AuditLogger::log(
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            purpose: $purpose,
            metadata: $metadata,
        );
    }
}
