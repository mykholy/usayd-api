<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\DTOs\AuthenticationResult;
use App\Modules\Identity\Application\DTOs\RegisterData;
use App\Modules\Identity\Application\Ports\AccountStore;
use App\Modules\Identity\Application\Ports\AuthenticationAudit;
use App\Modules\Identity\Application\Ports\IdentityTransaction;
use App\Modules\Identity\Application\Ports\SessionAuthenticator;
use App\Modules\Identity\Application\Ports\TokenManager;

final readonly class RegisterAction
{
    public function __construct(
        private AccountStore $accounts,
        private SessionAuthenticator $sessions,
        private TokenManager $tokens,
        private AuthenticationAudit $audit,
        private IdentityTransaction $transaction,
    ) {}

    public function execute(RegisterData $data): AuthenticationResult
    {
        $result = $this->transaction->run(function () use ($data): AuthenticationResult {
            $account = $this->accounts->register($data);
            $this->audit->record('auth.register.success', 'user', (string) $account->id, 'User account registered', $data->ip);

            return $data->stateful
                ? new AuthenticationResult(account: $account)
                : new AuthenticationResult(tokens: $this->tokens->issue($account->id, $data->deviceName));
        });

        if ($result->account !== null) {
            $this->sessions->establish($result->account->id);
        }

        return $result;
    }
}
