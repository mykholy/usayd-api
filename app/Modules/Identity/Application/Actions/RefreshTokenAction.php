<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\DTOs\AuthenticationResult;
use App\Modules\Identity\Application\DTOs\RefreshData;
use App\Modules\Identity\Application\Ports\TokenManager;

final readonly class RefreshTokenAction
{
    public function __construct(private TokenManager $tokens) {}

    public function execute(RefreshData $data): AuthenticationResult
    {
        return new AuthenticationResult(tokens: $this->tokens->rotate($data->refreshToken, $data->deviceName));
    }
}
