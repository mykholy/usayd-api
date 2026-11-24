<?php

namespace Tests\Feature\Queue;

use App\Jobs\SampleProcessingJob;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FailedJobLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_job_failure_emits_dead_letter_audit_log(): void
    {
        $job = new SampleProcessingJob(
            taskName: 'faulty_payment_sync',
            payload: ['quote_id' => 'q-99'],
            shouldFail: true
        );

        $exception = new RuntimeException('PSP payment gateway connection timeout');

        // Simulate terminal failure event
        $job->failed($exception);

        // Verify dead-letter audit event created
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => 'queue_dead_letter',
            'action' => 'job_failed_terminal',
        ]);

        $audit = AuditEvent::where('subject_type', 'queue_dead_letter')->first();
        $this->assertSame('faulty_payment_sync', $job->taskName);
        $this->assertSame('RuntimeException', $audit->metadata['exception_class']);
        $this->assertStringContainsString('PSP payment gateway connection timeout', $audit->metadata['exception_message']);
    }

    public function test_dead_letter_review_command_inspects_and_lists_failed_jobs(): void
    {
        $uuid = (string) Str::uuid();

        // Seed a failed job record
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'reports',
            'payload' => json_encode(['displayName' => SampleProcessingJob::class]),
            'exception' => "RuntimeException: Out of memory\nat /app/Jobs/SampleProcessingJob.php:30",
            'failed_at' => now(),
        ]);

        // Run dead-letter review CLI command
        $listExitCode = Artisan::call('usayd:queue-dead-letter', ['--action' => 'list']);
        $this->assertSame(0, $listExitCode);

        $output = Artisan::output();
        $this->assertStringContainsString($uuid, $output);
        $this->assertStringContainsString('reports', $output);

        // Inspect specific job
        $inspectExitCode = Artisan::call('usayd:queue-dead-letter', [
            '--action' => 'inspect',
            '--id' => $uuid,
        ]);
        $this->assertSame(0, $inspectExitCode);
        $inspectOutput = Artisan::output();
        $this->assertStringContainsString('Out of memory', $inspectOutput);
    }

    public function test_terminal_job_failure_sanitizes_credentials_in_exception_message(): void
    {
        $job = new SampleProcessingJob(
            taskName: 'faulty_credential_sync',
            payload: ['data' => '123'],
            shouldFail: true
        );

        $exception = new RuntimeException('Connection failed with password=super_secret_db_password and token: bearer_xyz123');

        $job->failed($exception);

        $audit = AuditEvent::where('action', 'job_failed_terminal')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('super_secret_db_password', $audit->metadata['exception_message']);
        $this->assertStringNotContainsString('bearer_xyz123', $audit->metadata['exception_message']);
        $this->assertStringContainsString('[REDACTED]', $audit->metadata['exception_message']);
    }
}
