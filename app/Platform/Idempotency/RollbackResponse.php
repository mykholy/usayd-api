<?php

declare(strict_types=1);

namespace App\Platform\Idempotency;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class RollbackResponse extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('IDEMPOTENT_OPERATION_ROLLED_BACK');
    }
}
