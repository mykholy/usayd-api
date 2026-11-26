<?php

namespace Tests\Unit\Storage;

use App\Platform\Storage\Application\Ports\MalwareScanner;
use App\Platform\Storage\Application\UploadSafetyGate;
use App\Platform\Storage\Domain\ScanVerdict;
use App\Platform\Storage\Domain\UploadRejected;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class UploadSafetyGateTest extends TestCase
{
    private const CONTENT = "Synthetic upload fixture.\n";

    public function test_exact_bytes_are_scanned_and_detected_type_is_returned(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects($this->once())->method('scan')
            ->with(self::CONTENT)->willReturn(ScanVerdict::Clean);

        $gate = new UploadSafetyGate($scanner);

        $this->assertSame('text/plain', $gate->verify(
            self::CONTENT, strlen(self::CONTENT), ['text/plain']
        ));
    }

    public function test_oversized_content_is_rejected_before_scanning(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects($this->never())->method('scan');

        $this->expectException(UploadRejected::class);
        $this->expectExceptionMessage(UploadRejected::SIZE);

        (new UploadSafetyGate($scanner))->verify(
            self::CONTENT, strlen(self::CONTENT) - 1, ['text/plain']
        );
    }

    public function test_empty_content_is_rejected(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects($this->never())->method('scan');

        $this->expectException(UploadRejected::class);
        $this->expectExceptionMessage(UploadRejected::SIZE);

        (new UploadSafetyGate($scanner))->verify('', 100, ['text/plain']);
    }

    public function test_non_positive_size_limit_is_rejected(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects($this->never())->method('scan');

        $this->expectException(UploadRejected::class);
        $this->expectExceptionMessage(UploadRejected::SIZE);

        (new UploadSafetyGate($scanner))->verify(self::CONTENT, 0, ['text/plain']);
    }

    public function test_detected_type_must_be_in_server_allowlist(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects($this->never())->method('scan');

        $this->expectException(UploadRejected::class);
        $this->expectExceptionMessage(UploadRejected::TYPE);

        (new UploadSafetyGate($scanner))->verify(self::CONTENT, 100, ['image/png']);
    }

    public function test_empty_allowlist_rejects_content(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects($this->never())->method('scan');

        $this->expectException(UploadRejected::class);
        $this->expectExceptionMessage(UploadRejected::TYPE);

        (new UploadSafetyGate($scanner))->verify(self::CONTENT, 100, []);
    }

    public function test_infected_content_is_rejected(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects($this->once())->method('scan')->willReturn(ScanVerdict::Infected);

        $this->expectException(UploadRejected::class);
        $this->expectExceptionMessage(UploadRejected::INFECTED);

        (new UploadSafetyGate($scanner))->verify(self::CONTENT, 100, ['text/plain']);
    }

    public function test_unavailable_scanner_fails_closed(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects($this->once())->method('scan')->willReturn(ScanVerdict::Unavailable);

        $this->expectException(UploadRejected::class);
        $this->expectExceptionMessage(UploadRejected::SCAN_UNAVAILABLE);

        (new UploadSafetyGate($scanner))->verify(self::CONTENT, 100, ['text/plain']);
    }

    public function test_scanner_exception_fails_closed_without_leaking_details(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects($this->once())->method('scan')
            ->willThrowException(new RuntimeException('private scanner connection detail'));

        try {
            (new UploadSafetyGate($scanner))->verify(self::CONTENT, 100, ['text/plain']);
            $this->fail('Scanner failure must reject the upload.');
        } catch (UploadRejected $exception) {
            $this->assertSame(UploadRejected::SCAN_UNAVAILABLE, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}
