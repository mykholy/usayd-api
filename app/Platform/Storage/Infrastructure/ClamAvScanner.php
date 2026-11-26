<?php

namespace App\Platform\Storage\Infrastructure;

use App\Platform\Storage\Application\Ports\MalwareScanner;
use App\Platform\Storage\Domain\ScanVerdict;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final readonly class ClamAvScanner implements MalwareScanner
{
    public function __construct(
        private string $executable = '/usr/bin/clamscan',
        private float $timeoutSeconds = 30.0,
        private ?string $signatureDatabase = null,
    ) {}

    public function scan(string $content): ScanVerdict
    {
        if ($this->executable === '' || $this->timeoutSeconds <= 0 || ! is_finite($this->timeoutSeconds)) {
            return ScanVerdict::Unavailable;
        }

        $command = [$this->executable, '--no-summary', '--alert-exceeds-max=yes'];

        if ($this->signatureDatabase !== null) {
            $command[] = '--database='.$this->signatureDatabase;
        }

        $command[] = '-';

        try {
            // Pass bounded bytes through stdin without a shell or client filename.
            $process = new Process($command);
            $process->setInput($content);
            $process->setTimeout($this->timeoutSeconds);
            $process->disableOutput();

            return match ($process->run()) {
                0 => ScanVerdict::Clean,
                1 => ScanVerdict::Infected,
                default => ScanVerdict::Unavailable,
            };
        } catch (ExceptionInterface) {
            return ScanVerdict::Unavailable;
        }
    }
}
