<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\LearningPlan;
use App\Models\User;
use App\Services\Storage\SignedUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StorageController extends Controller
{
    public function __construct(
        protected SignedUrlService $signedUrlService
    ) {}

    /**
     * POST /api/v1/storage/signed-url
     * Authenticated endpoint for requesting a signed download URL.
     */
    public function generateSignedUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string',
            'expires_in' => 'nullable|integer|min:60|max:3600',
            'owner_id' => 'nullable|integer',
            'disk' => 'nullable|string|in:private,public,s3,s3_private',
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $targetOwnerId = $validated['owner_id'] ?? $actor->id;

        $path = $this->signedUrlService->sanitizePath($validated['path']);

        // Authorization check: learner cannot access another learner's private files
        $this->authorizeFileAccess($actor, (int) $targetOwnerId);

        $result = $this->signedUrlService->generateSignedUrl(
            path: $path,
            expiresInSeconds: $validated['expires_in'] ?? SignedUrlService::DEFAULT_EXPIRATION_SECONDS,
            ownerId: (int) $targetOwnerId,
            disk: $validated['disk'] ?? 'private',
            actor: $actor
        );

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * GET /api/v1/storage/download
     * Publicly accessible signed download endpoint with cryptographic token verification.
     */
    public function download(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $this->signedUrlService->validateSignedRequest($request);
        $path = $validated['path'];
        $disk = $validated['disk'];

        if (! Storage::disk($disk)->exists($path)) {
            throw ApiException::notFound(__('api.storage_file_missing'));
        }

        // Audit event for verified file download
        AuditEvent::create([
            'trace_id' => (string) Str::uuid(),
            'subject_type' => 'storage_file',
            'subject_id' => $path,
            'action' => 'storage.file_downloaded',
            'purpose' => "Verified signed download of [{$path}] from disk [{$disk}]",
            'metadata' => [
                'path' => $path,
                'disk' => $disk,
                'client_ip' => $request->ip(),
            ],
        ]);

        return Storage::disk($disk)->download($path, null, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    /**
     * Check if actor is allowed to access target student's files.
     */
    protected function authorizeFileAccess(User $actor, int $targetOwnerId): void
    {
        // 1. If actor is the owner, allowed
        if ((int) $actor->id === $targetOwnerId) {
            return;
        }

        // 2. Admins and academic supervisors have overarching oversight
        if (in_array($actor->role, ['admin', 'academic'], true)) {
            return;
        }

        // 3. Teachers are allowed if the student is assigned to them in a LearningPlan
        if ($actor->role === 'teacher') {
            $isAssigned = LearningPlan::where('teacher_id', $actor->id)
                ->where('user_id', $targetOwnerId)
                ->exists();

            if ($isAssigned) {
                return;
            }
        }

        // Deny all other access
        throw ApiException::forbidden(__('api.storage_access_denied'));
    }
}
