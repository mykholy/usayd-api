<?php

namespace App\Platform\Storage\Application;

use App\Platform\Storage\Application\Ports\MalwareScanner;
use App\Platform\Storage\Domain\ScanVerdict;
use App\Platform\Storage\Domain\UploadRejected;
use finfo;
use Throwable;

final readonly class UploadSafetyGate
{
    public function __construct(private MalwareScanner $scanner) {}

    /**
     * The caller must bound the read before allocating the payload, supply
     * server-owned limits and persist these same bytes only after success.
     *
     * @param  list<string>  $allowedContentTypes
     * @return string Detected content type; never trust client MIME or filename.
     */
    public function verify(string $content, int $maxBytes, array $allowedContentTypes): string
    {
        if ($maxBytes < 1 || $content === '' || strlen($content) > $maxBytes) {
            throw new UploadRejected(UploadRejected::SIZE);
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! is_string($detected) || ! in_array($detected, $allowedContentTypes, true)) {
            throw new UploadRejected(UploadRejected::TYPE);
        }

        try {
            $verdict = $this->scanner->scan($content);
        } catch (Throwable) {
            // Do not expose a scanner exception or potentially sensitive bytes.
            throw new UploadRejected(UploadRejected::SCAN_UNAVAILABLE);
        }

        if ($verdict !== ScanVerdict::Clean) {
            throw new UploadRejected($verdict === ScanVerdict::Infected
                ? UploadRejected::INFECTED
                : UploadRejected::SCAN_UNAVAILABLE);
        }

        return $detected;
    }
}
