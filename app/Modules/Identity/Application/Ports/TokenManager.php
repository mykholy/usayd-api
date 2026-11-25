<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Ports;

interface TokenManager
{
    /** @return array{access_token:string, expires_in:int, refresh_token:string, token_type:string} */
    public function issue(int $accountId, string $deviceName): array;

    /** @return array{access_token:string, expires_in:int, refresh_token:string, token_type:string} */
    public function rotate(#[\SensitiveParameter] string $refreshToken, string $deviceName): array;

    public function revoke(int $accountId, bool $allDevices, ?int $tokenId): void;
}
