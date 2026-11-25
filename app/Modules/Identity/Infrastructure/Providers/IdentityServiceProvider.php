<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Modules\Identity\Application\Ports\AccountStore;
use App\Modules\Identity\Application\Ports\AuthenticationAudit;
use App\Modules\Identity\Application\Ports\IdentityTransaction;
use App\Modules\Identity\Application\Ports\LoginAttempts;
use App\Modules\Identity\Application\Ports\SessionAuthenticator;
use App\Modules\Identity\Application\Ports\TokenManager;
use App\Modules\Identity\Infrastructure\Audit\EloquentAuthenticationAudit;
use App\Modules\Identity\Infrastructure\Authentication\LaravelLoginAttempts;
use App\Modules\Identity\Infrastructure\Authentication\LaravelSessionAuthenticator;
use App\Modules\Identity\Infrastructure\Authentication\SanctumTokenManager;
use App\Modules\Identity\Infrastructure\Persistence\DatabaseIdentityTransaction;
use App\Modules\Identity\Infrastructure\Persistence\EloquentAccountStore;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountStore::class, EloquentAccountStore::class);
        $this->app->bind(IdentityTransaction::class, DatabaseIdentityTransaction::class);
        $this->app->bind(AuthenticationAudit::class, EloquentAuthenticationAudit::class);
        $this->app->bind(LoginAttempts::class, LaravelLoginAttempts::class);
        $this->app->bind(SessionAuthenticator::class, LaravelSessionAuthenticator::class);
        $this->app->bind(TokenManager::class, SanctumTokenManager::class);
    }
}
