<?php

namespace App\Services\Storage;

use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SignedUrlService
{
    public const MIN_EXPIRATION_SECONDS = 60; // 1 minute

    public const MAX_EXPIRATION_SECONDS = 3600; // 1 hour

    public const DEFAULT_EXPIRATION_SECONDS = 900; // 15 minutes

    public function __construct(private readonly OwnedStoragePath $ownedPath) {}

    /**
     * Generate a cryptographic HMAC-SHA256 signed URL for accessing a private storage asset.
     */
    public function generateSignedUrl(
        string $path,
        int $expiresInSeconds = self::DEFAULT_EXPIRATION_SECONDS,
        ?int $ownerId = null,
        string $disk = 'private',
        ?User $actor = null
    ): array {
        $cleanPath = $this->sanitizePath($path);
        $this->ownedPath->assertOwned($cleanPath, $ownerId, $disk);

        $clampedExpiresIn = max(self::MIN_EXPIRATION_SECONDS, min(self::MAX_EXPIRATION_SECONDS, $expiresInSeconds));
        $expiresAt = now()->addSeconds($clampedExpiresIn)->timestamp;

        $signature = $this->createSignature($cleanPath, $expiresAt, $ownerId, $disk);

        $params = [
            'path' => $cleanPath,
            'expires' => $expiresAt,
            'disk' => $disk,
            'signature' => $signature,
        ];

        if ($ownerId !== null) {
            $params['owner_id'] = $ownerId;
        }

        $url = url('/api/v1/storage/download?'.http_build_query($params));

        // Audit logging
        AuditEvent::create([
            'trace_id' => (string) Str::uuid(),
            'actor_id' => $actor ? (string) $actor->id : null,
            'subject_type' => 'storage_signed_url',
            'subject_id' => $cleanPath,
            'action' => 'storage.signed_url_generated',
            'purpose' => "Signed URL generated for private path [{$cleanPath}] expiring in [{$clampedExpiresIn}s]",
            'metadata' => [
                'path' => $cleanPath,
                'disk' => $disk,
                'owner_id' => $ownerId,
                'expires_at' => date(DATE_ATOM, $expiresAt),
            ],
        ]);

        return [
            'url' => $url,
            'path' => $cleanPath,
            'expires_at' => date(DATE_ATOM, $expiresAt),
            'expires_in' => $clampedExpiresIn,
            'disk' => $disk,
        ];
    }

    /**
     * Validate the incoming download request's signature, expiry, and path.
     * Returns array with sanitized path and disk, or throws ApiException.
     */
    public function validateSignedRequest(Request $request): array
    {
        $path = $request->query('path');
        $expires = (int) $request->query('expires');
        $signature = (string) $request->query('signature');
        $ownerId = $request->query('owner_id') !== null ? (int) $request->query('owner_id') : null;
        $disk = (string) ($request->query('disk') ?? 'private');

        if (! is_string($path) || $path === '' || ! $expires || ! $signature) {
            throw new ApiException(400, 'BAD_REQUEST', __('api.storage_signed_parameters'));
        }

        $cleanPath = $this->sanitizePath($path);

        // Check expiration
        if (now()->timestamp > $expires) {
            throw new ApiException(403, 'FORBIDDEN', __('api.storage_expired'));
        }

        // Verify cryptographic signature
        $expectedSignature = $this->createSignature($cleanPath, $expires, $ownerId, $disk);
        if (! hash_equals($expectedSignature, $signature)) {
            throw new ApiException(403, 'FORBIDDEN', __('api.storage_signature_invalid'));
        }

        $this->ownedPath->assertOwned($cleanPath, $ownerId, $disk);

        return [
            'path' => $cleanPath,
            'disk' => $disk,
            'owner_id' => $ownerId,
        ];
    }

    /**
     * Path traversal protection and path normalization.
     */
    public function sanitizePath(string $path): string
    {
        // 1. Check for null bytes or illegal control characters
        if (str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new ApiException(400, 'BAD_REQUEST', __('api.storage_control_characters'));
        }

        // 2. Normalize Windows backslashes to forward slashes
        $normalized = str_replace('\\', '/', $path);

        // 3. Strict directory traversal detection ('..' segments)
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new ApiException(400, 'BAD_REQUEST', __('api.storage_traversal'));
            }
        }

        // 4. Remove leading/trailing slashes
        $trimmed = trim($normalized, '/');

        if ($trimmed === '') {
            throw new ApiException(400, 'BAD_REQUEST', __('api.storage_empty_path'));
        }

        return $trimmed;
    }

    /**
     * Cryptographic HMAC-SHA256 signature generation.
     */
    public function createSignature(string $path, int $expiresAt, ?int $ownerId, string $disk): string
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }

        $dataToSign = "path={$path}&expires={$expiresAt}&owner_id={$ownerId}&disk={$disk}";

        return hash_hmac('sha256', $dataToSign, $appKey);
    }
}
