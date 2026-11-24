<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class TokenService
{
    public const ACCESS_TOKEN_EXPIRATION_MINUTES = 60;

    public const REFRESH_TOKEN_EXPIRATION_DAYS = 30;

    /**
     * Issue an access token and refresh token pair for a user.
     *
     * @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string}
     */
    public function issueTokens(User $user, ?string $deviceName = 'mobile-device'): array
    {
        $sanitizedDevice = preg_replace('/[^a-zA-Z0-9_\-\.]/', '-', (string) $deviceName) ?: 'device';

        // Issue access token (expires in 60 minutes)
        $accessExpiresAt = now()->addMinutes(self::ACCESS_TOKEN_EXPIRATION_MINUTES);
        $accessToken = $user->createToken(
            "{$sanitizedDevice}:access",
            ['access-api'],
            $accessExpiresAt
        );

        // Issue refresh token (expires in 30 days)
        $refreshExpiresAt = now()->addDays(self::REFRESH_TOKEN_EXPIRATION_DAYS);
        $refreshToken = $user->createToken(
            "{$sanitizedDevice}:refresh",
            ['issue-token'],
            $refreshExpiresAt
        );

        return [
            'access_token' => $accessToken->plainTextToken,
            'expires_in' => self::ACCESS_TOKEN_EXPIRATION_MINUTES * 60,
            'refresh_token' => $refreshToken->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Rotate a refresh token: atomically locks and revokes the old refresh token inside a database transaction
     * and issues a new replacement pair.
     *
     * @return array{access_token: string, expires_in: int, refresh_token: string, token_type: string}
     *
     * @throws ApiException
     */
    public function rotateRefreshToken(string $plainRefreshToken, ?string $deviceName = 'mobile-device'): array
    {
        return DB::transaction(function () use ($plainRefreshToken, $deviceName) {
            $token = null;

            if (str_contains($plainRefreshToken, '|')) {
                [$id, $plain] = explode('|', $plainRefreshToken, 2);
                /** @var PersonalAccessToken|null $record */
                $record = PersonalAccessToken::where('id', $id)->lockForUpdate()->first();
                if ($record && hash_equals($record->token, hash('sha256', $plain))) {
                    $token = $record;
                }
            } else {
                $token = PersonalAccessToken::where('token', hash('sha256', $plainRefreshToken))->lockForUpdate()->first();
            }

            if (! $token) {
                throw ApiException::unauthorized('Invalid or unrecognised refresh token.');
            }

            if (! $token->can('issue-token')) {
                throw ApiException::forbidden('The provided token does not hold refresh authorization.');
            }

            if ($token->expires_at && $token->expires_at->isPast()) {
                $token->delete();
                throw ApiException::unauthorized('Refresh token has expired.');
            }

            /** @var User|null $user */
            $user = $token->tokenable;
            if (! $user instanceof User) {
                $token->delete();
                throw ApiException::unauthorized('Associated user account is no longer valid.');
            }

            $tokenName = (string) $token->name;
            $devicePrefix = str_contains($tokenName, ':')
                ? explode(':', $tokenName, 2)[0]
                : (preg_replace('/[^a-zA-Z0-9_\-\.]/', '-', (string) $deviceName) ?: 'mobile');

            // Delete the consumed refresh token immediately inside the transaction lock
            $token->delete();

            // Revoke any existing active access tokens for this device family
            $user->tokens()->where('name', "{$devicePrefix}:access")->delete();

            // Issue new token pair
            $newTokens = $this->issueTokens($user, $devicePrefix);

            AuditEvent::create([
                'subject_type' => 'user',
                'subject_id' => (string) $user->id,
                'action' => 'auth.token.rotated',
                'purpose' => 'Mobile token rotation contract execution',
                'metadata' => [
                    'device_name' => $devicePrefix,
                ],
            ]);

            return $newTokens;
        });
    }

    /**
     * Revoke the current device token family (access token + refresh token) from the request.
     */
    public function revokeCurrentDeviceTokens(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        if (method_exists($user, 'currentAccessToken') && ($token = $user->currentAccessToken())) {
            $tokenName = (string) $token->name;
            if (str_contains($tokenName, ':')) {
                [$devicePrefix] = explode(':', $tokenName, 2);
                // Revoke both access and refresh tokens for this device
                $user->tokens()->where('name', 'like', "{$devicePrefix}:%")->delete();
            } else {
                $token->delete();
            }
        }
    }

    /**
     * Revoke the current access token from the request (backward-compatible alias).
     */
    public function revokeCurrentToken(Request $request): void
    {
        $this->revokeCurrentDeviceTokens($request);
    }

    /**
     * Revoke all personal access tokens for the given user.
     */
    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }
}
