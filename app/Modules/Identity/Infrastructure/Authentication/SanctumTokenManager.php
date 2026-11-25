<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Authentication;

use App\Models\User;
use App\Modules\Identity\Application\Ports\TokenManager;
use App\Services\Auth\TokenService;

final readonly class SanctumTokenManager implements TokenManager
{
    public function __construct(private TokenService $tokens) {}

    public function issue(int $accountId, string $deviceName): array
    {
        return $this->tokens->issueTokens(User::query()->findOrFail($accountId), $deviceName);
    }

    public function rotate(#[\SensitiveParameter] string $refreshToken, string $deviceName): array
    {
        return $this->tokens->rotateRefreshToken($refreshToken, $deviceName);
    }

    public function revoke(int $accountId, bool $allDevices, ?int $tokenId, ?string $tokenName): void
    {
        $user = User::query()->findOrFail($accountId);

        if ($allDevices) {
            $this->tokens->revokeAllTokens($user);

            return;
        }

        if ($tokenId !== null && $tokenName !== null) {
            $this->tokens->revokeAuthenticatedToken($user, $tokenId, $tokenName);
        }
    }
}
