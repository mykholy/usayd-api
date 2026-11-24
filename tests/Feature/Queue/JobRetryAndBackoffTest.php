<?php

namespace Tests\Feature\Queue;

use App\Jobs\SampleProcessingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JobRetryAndBackoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_job_defines_exponential_backoff_and_tries(): void
    {
        $job = new SampleProcessingJob(taskName: 'backoff_test');

        $this->assertSame(3, $job->tries, 'Job must allow 3 tries.');
        $this->assertSame(3, $job->maxExceptions, 'Job must allow 3 max exceptions.');
        $this->assertSame([5, 15, 60], $job->backoff(), 'Job backoff must be exponential [5, 15, 60] seconds.');
    }

    public function test_job_dispatches_successfully_to_queue(): void
    {
        Queue::fake();

        SampleProcessingJob::dispatch('sync_attendance_task', ['lesson_id' => 'lsn-100']);

        Queue::assertPushed(SampleProcessingJob::class, function ($job) {
            return $job->taskName === 'sync_attendance_task'
                && $job->queue === 'default'
                && $job->payload['lesson_id'] === 'lsn-100';
        });
    }

    public function test_job_can_target_high_priority_partition(): void
    {
        Queue::fake();

        SampleProcessingJob::dispatch('urgent_otp_notification')
            ->onQueue('high');

        Queue::assertPushedOn('high', SampleProcessingJob::class);
    }
}
