<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Exceptions\ApiException;

final class OwnedStoragePath
{
    public function assertOwned(string $path, ?int $ownerId, string $disk): void
    {
        // Only explicitly registered owner-scoped namespaces are signable.
        $segments = explode('/', $path);

        if ($ownerId === null || $ownerId < 1
            || count($segments) < 3
            || ! in_array($segments[0], config('storage.owned_namespaces', []), true)
            || $segments[1] !== (string) $ownerId
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || ! in_array($disk, config('storage.signed_disks', []), true)) {
            throw ApiException::forbidden(__('api.storage_access_denied'));
        }
    }
}
