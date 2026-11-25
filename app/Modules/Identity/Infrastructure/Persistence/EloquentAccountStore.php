<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Identity\Application\DTOs\Account;
use App\Modules\Identity\Application\DTOs\RegisterData;
use App\Modules\Identity\Application\Ports\AccountStore;
use Illuminate\Support\Facades\Hash;

final class EloquentAccountStore implements AccountStore
{
    public function authenticate(string $email, #[\SensitiveParameter] string $password): ?Account
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password) || $user->status !== 'active') {
            return null;
        }

        return $this->snapshot($user);
    }

    public function register(RegisterData $data): Account
    {
        $user = User::query()->create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => Hash::make($data->password),
            'phone' => $data->phone,
            'locale' => $data->locale,
            'country' => config('identity.registration_country'),
            'timezone' => config('identity.registration_timezone'),
            'status' => 'active',
        ]);

        return $this->snapshot($user);
    }

    private function snapshot(User $user): Account
    {
        return new Account(
            (int) $user->id,
            (string) $user->name,
            (string) $user->email,
            (string) ($user->locale ?? 'ar'),
            (string) $user->role,
            (string) $user->status,
            (string) $user->timezone,
        );
    }
}
