<?php

namespace App\Platform\Storage\Domain;

use RuntimeException;

final class UploadRejected extends RuntimeException
{
    public const SIZE = 'upload_size_invalid';

    public const TYPE = 'upload_content_type_denied';

    public const INFECTED = 'upload_malware_detected';

    public const SCAN_UNAVAILABLE = 'upload_scan_unavailable';
}
