<?php

namespace Tests\Unit\Storage;

use App\Platform\Storage\Domain\ScanVerdict;
use App\Platform\Storage\Infrastructure\ClamAvScanner;
use PHPUnit\Framework\TestCase;

class ClamAvScannerTest extends TestCase
{
    public function test_missing_executable_fails_closed(): void
    {
        $scanner = new ClamAvScanner('/nonexistent/usayd-clamscan', 1.0);

        $this->assertSame(ScanVerdict::Unavailable, $scanner->scan('synthetic fixture'));
    }

    public function test_invalid_timeout_fails_closed(): void
    {
        $scanner = new ClamAvScanner(timeoutSeconds: 0);

        $this->assertSame(ScanVerdict::Unavailable, $scanner->scan('synthetic fixture'));
    }

    public function test_real_engine_accepts_clean_and_rejects_synthetic_signature(): void
    {
        if (getenv('USAYD_REQUIRE_CLAMAV_TESTS') !== '1') {
            $this->markTestSkipped('Requires the isolated CI ClamAV engine.');
        }

        $database = tempnam(sys_get_temp_dir(), 'usayd-sig-');
        $this->assertNotFalse($database);
        $signaturePath = $database.'.ndb';

        try {
            $fixture = 'USAYD-CI-SYNTHETIC-SIGNATURE-2026';
            $signature = 'Usayd.Test.Signature:0:*:'.bin2hex($fixture)."\n";
            $this->assertNotFalse(file_put_contents($signaturePath, $signature));

            $scanner = new ClamAvScanner(signatureDatabase: $signaturePath);
            $this->assertSame(ScanVerdict::Clean, $scanner->scan('Harmless test content.'));
            $this->assertSame(ScanVerdict::Infected, $scanner->scan($fixture));

            $missingDatabase = new ClamAvScanner(signatureDatabase: $signaturePath.'.missing');
            $this->assertSame(ScanVerdict::Unavailable, $missingDatabase->scan('Harmless test content.'));
        } finally {
            if (is_file($signaturePath)) {
                unlink($signaturePath);
            }
            unlink($database);
        }
    }
}
