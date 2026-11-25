<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class TokenService
{
    public const ACCESS_TOKEN_EXPIRATION_MINUTES = 60;

    public const REFRESH_TOKEN_EXPIRATION_DAYS = 30;

    /** @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string} */
    public function issueTokens(User $user, ?string $deviceName = 'mobile-device'): array
    {
        return DB::transaction(function () use ($user, $deviceName): array {
            $account = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if ($account->status !== 'active') {
                throw ApiException::unauthorized(__('identity.invalid_credentials'));
            }

            $device = preg_replace('/[^a-zA-Z0-9_\-\.]/', '-', (string) $deviceName) ?: 'device';

            return $this->issuePair($account, $device.':'.Str::uuid());
        });
    }

    /** @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string} */
    private function issuePair(User $user, string $family): array
    {
        $access = $user->createToken($family.':access', ['access-api'], now()->addMinutes(self::ACCESS_TOKEN_EXPIRATION_MINUTES));
        $refresh = $user->createToken($family.':refresh', ['issue-token'], now()->addDays(self::REFRESH_TOKEN_EXPIRATION_DAYS));

        return [
            'access_token' => $access->plainTextToken,
            'expires_in' => self::ACCESS_TOKEN_EXPIRATION_MINUTES * 60,
            'refresh_token' => $refresh->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }

    /** @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string} */
    public function rotateRefreshToken(#[\SensitiveParameter] string $plainRefreshToken, ?string $deviceName = 'mobile-device'): array
    {
        // Resolve identity first, then acquire locks in account -> token order.
        $candidate = PersonalAccessToken::findToken($plainRefreshToken);
        if (! $candidate || $candidate->tokenable_type !== (new User)->getMorphClass()) {
            throw ApiException::unauthorized(__('identity.invalid_refresh'));
        }

        $result = DB::transaction(function () use ($candidate, $plainRefreshToken, $deviceName): array|ApiException {
            $user = User::query()->lockForUpdate()->find($candidate->tokenable_id);
            $token = PersonalAccessToken::query()->lockForUpdate()->find($candidate->getKey());

            $plain = str_contains($plainRefreshToken, '|') ? explode('|', $plainRefreshToken, 2)[1] : $plainRefreshToken;
            if (! $token || ! hash_equals($token->token, hash('sha256', $plain))) {
                return ApiException::unauthorized(__('identity.invalid_refresh'));
            }

            if (! $token->can('issue-token')) {
                return ApiException::forbidden(__('identity.refresh_forbidden'));
            }

            if ($token->expires_at && $token->expires_at->isPast()) {
                $token->delete();

                return ApiException::unauthorized(__('identity.refresh_expired'));
            }

            if (! $user || $user->status !== 'active') {
                $token->delete();

                return ApiException::unauthorized(__('identity.invalid_refresh'));
            }

            $family = $this->familyName((string) $token->name);
            $this->deleteFamily($user, $family, (int) $token->id);

            // Deterministically map legacy families so a concurrent logout can revoke the replacement.
            if ($family === null) {
                $device = preg_replace('/[^a-zA-Z0-9_\-\.]/', '-', (string) $deviceName) ?: 'mobile';
                $family = $device.':'.Str::uuid();
            } elseif (! str_contains($family, ':')) {
                $family = $this->legacyReplacement($user, $family);
            }

            $tokens = $this->issuePair($user, $family);

            AuditEvent::create([
                'subject_type' => 'user',
                'subject_id' => (string) $user->id,
                'action' => 'auth.token.rotated',
                'purpose' => 'Mobile token rotation contract execution',
                'metadata' => ['device_name' => explode(':', $family, 2)[0]],
            ]);

            return $tokens;
        });

        // Throw after commit so invalid or expired token cleanup is not rolled back.
        if ($result instanceof ApiException) {
            throw $result;
        }

        return $result;
    }

    public function revokeCurrentDeviceTokens(Request $request): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user instanceof User && $token instanceof PersonalAccessToken) {
            $this->revokeDeviceToken($user, $token);
        }
    }

    public function revokeDeviceToken(User $user, PersonalAccessToken $token): void
    {
        if ((int) $token->tokenable_id !== (int) $user->id || $token->tokenable_type !== $user->getMorphClass()) {
            return;
        }

        $this->revokeAuthenticatedToken($user, (int) $token->id, (string) $token->name);
    }

    /**
     * Token identity is captured from the authenticated server-side model, never request input.
     */
    public function revokeAuthenticatedToken(User $user, int $tokenId, string $tokenName): void
    {
        $family = $this->familyName($tokenName);

        DB::transaction(function () use ($user, $tokenId, $family): void {
            $account = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->deleteFamily($account, $family, $tokenId);
        });
    }

    private function familyName(string $name): ?string
    {
        foreach ([':access', ':refresh'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return null;
    }

    private function legacyReplacement(User $user, string $family): string
    {
        return $family.':legacy-'.substr(hash('sha256', $user->id.'|'.$family), 0, 32);
    }

    private function deleteFamily(User $user, ?string $family, int $tokenId): void
    {
        if ($family === null) {
            $user->tokens()->whereKey($tokenId)->delete();

            return;
        }

        $names = [$family.':access', $family.':refresh'];
        if (! str_contains($family, ':')) {
            $replacement = $this->legacyReplacement($user, $family);
            $names[] = $replacement.':access';
            $names[] = $replacement.':refresh';
        }

        $user->tokens()->whereIn('name', $names)->delete();
    }

    public function revokeCurrentToken(Request $request): void
    {
        $this->revokeCurrentDeviceTokens($request);
    }

    public function revokeAllTokens(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::query()->lockForUpdate()->findOrFail($user->getKey())->tokens()->delete();
        });
    }
}
