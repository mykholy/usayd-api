<?php

namespace Tests\Feature\Concurrency;

use App\Models\AuditEvent;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ParallelProcessConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        AuditEvent::where('subject_type', 'concurrency_counter')->delete();
        parent::tearDown();
    }

    public function test_concurrent_parallel_worker_processes_increment_safely(): void
    {
        $target = AuditEvent::create([
            'subject_type' => 'concurrency_counter',
            'subject_id' => 'counter-1',
            'action' => 'initialize',
            'purpose' => '0',
            'metadata' => ['count' => 0],
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

        $finalRecord = AuditEvent::findOrFail($targetId);
        $expectedCount = $numWorkers * $incrementsPerWorker;

        $this->assertSame(
            $expectedCount,
            $finalRecord->metadata['count'],
            "Counter must equal exactly {$expectedCount} with zero lost updates under parallel concurrency."
        );
    }
}
