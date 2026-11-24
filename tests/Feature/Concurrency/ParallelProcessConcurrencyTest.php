<?php

namespace Tests\Feature\Concurrency;

use App\Models\SettingsVersion;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ParallelProcessConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        SettingsVersion::where('namespace', 'concurrency')->delete();
        parent::tearDown();
    }

    public function test_concurrent_parallel_worker_processes_increment_safely(): void
    {
        $target = SettingsVersion::create([
            'namespace' => 'concurrency',
            'scope' => 'parallel_counter',
            'settings' => ['count' => 0],
            'checksum' => hash('sha256', '0'),
            'status' => 'active',
        ]);

        $targetId = $target->id;
        $numWorkers = 3;
        $incrementsPerWorker = 5;

        $artisanPath = base_path('artisan');

        $processes = [];
        for ($w = 0; $w < $numWorkers; $w++) {
            $p = new Process(['php', $artisanPath, 'usayd:concurrency-worker', "--id={$targetId}", "--increments={$incrementsPerWorker}", '--env=testing']);
            $p->start();
            $processes[] = $p;
        }

        // Wait for all workers to finish
        foreach ($processes as $p) {
            $p->wait();
            $this->assertSame(
                0,
                $p->getExitCode(),
                'Worker process error: '.$p->getErrorOutput().' Output: '.$p->getOutput()
            );
        }

        $finalRecord = SettingsVersion::findOrFail($targetId);
        $expectedCount = $numWorkers * $incrementsPerWorker;

        $this->assertSame(
            $expectedCount,
            $finalRecord->settings['count'],
            "Counter must equal exactly {$expectedCount} with zero lost updates under parallel concurrency."
        );
    }
}
