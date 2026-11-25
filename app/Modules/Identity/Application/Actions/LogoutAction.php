<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Ports\AuthenticationAudit;
use App\Modules\Identity\Application\Ports\SessionAuthenticator;
use App\Modules\Identity\Application\Ports\TokenManager;

final readonly class LogoutAction
{
    public function __construct(
        private TokenManager $tokens,
        private SessionAuthenticator $sessions,
        private AuthenticationAudit $audit,
    ) {}

    public function execute(int $accountId, bool $allDevices, ?int $tokenId, bool $stateful, string $ip): void
    {
        $this->tokens->revoke($accountId, $allDevices, $tokenId);

        if ($stateful) {
            $this->sessions->destroy();
        }

        $this->audit->record('auth.logout', 'user', (string) $accountId, 'Session/token terminated', $ip, ['all_devices' => $allDevices]);
    }
}
