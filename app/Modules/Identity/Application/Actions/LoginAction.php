<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\DTOs\AuthenticationResult;
use App\Modules\Identity\Application\DTOs\LoginData;
use App\Modules\Identity\Application\Ports\AccountStore;
use App\Modules\Identity\Application\Ports\AuthenticationAudit;
use App\Modules\Identity\Application\Ports\IdentityTransaction;
use App\Modules\Identity\Application\Ports\LoginAttempts;
use App\Modules\Identity\Application\Ports\SessionAuthenticator;
use App\Modules\Identity\Application\Ports\TokenManager;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentials;
use App\Modules\Identity\Domain\Exceptions\LoginThrottled;

final readonly class LoginAction
{
    public function __construct(
        private AccountStore $accounts,
        private LoginAttempts $attempts,
        private SessionAuthenticator $sessions,
        private TokenManager $tokens,
        private AuthenticationAudit $audit,
        private IdentityTransaction $transaction,
    ) {}

    public function execute(LoginData $data): AuthenticationResult
    {
        $retryAfter = $this->attempts->retryAfter($data->email, $data->ip);

        if ($retryAfter !== null) {
            $this->audit->record('auth.login.throttled', 'auth', $data->email, 'Brute-force protection trigger', $data->ip, ['retry_after' => $retryAfter]);

            throw new LoginThrottled($retryAfter);
        }

        $account = $this->accounts->authenticate($data->email, $data->password);

        if ($account === null) {
            $this->attempts->fail($data->email, $data->ip);
            $this->audit->record('auth.login.failed', 'auth', $data->email, 'Failed credential submission', $data->ip);

            throw new InvalidCredentials;
        }

        $this->attempts->clear($data->email, $data->ip);

        if ($data->stateful) {
            $this->sessions->establish($account->id);
            $this->audit->record('auth.login.spa', 'user', (string) $account->id, 'SPA cookie session authenticated', $data->ip);

            return new AuthenticationResult(account: $account);
        }

        return $this->transaction->run(function () use ($account, $data): AuthenticationResult {
            $tokens = $this->tokens->issue($account->id, $data->deviceName);
            $this->audit->record('auth.login.token', 'user', (string) $account->id, 'Mobile token issued', $data->ip, ['device_name' => $data->deviceName]);

            return new AuthenticationResult(tokens: $tokens);
        });
    }
}
