<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Authentication;

use App\Modules\Identity\Application\Ports\SessionAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final readonly class LaravelSessionAuthenticator implements SessionAuthenticator
{
    public function __construct(private Request $request) {}

    public function establish(int $accountId): void
    {
        Auth::guard('web')->loginUsingId($accountId);
        $this->request->session()->regenerate();
    }

    public function destroy(): void
    {
        Auth::guard('web')->logout();
        $this->request->session()->invalidate();
        $this->request->session()->regenerateToken();
    }
}
