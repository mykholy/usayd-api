<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationRollbackTest extends TestCase
{
    public function test_migrations_can_safely_rollback_and_reapply(): void
    {
        // Ensure migrations are freshly applied
        Artisan::call('migrate', ['--force' => true]);

        $expectedTables = [
            'users',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'audit_events',
            'outbox_messages',
            'idempotency_keys',
            'settings_versions',
            'feature_flags',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Table {$table} must exist after migrate."
            );
        }

        // Verify users table custom columns
        $this->assertTrue(Schema::hasColumn('users', 'phone'));
        $this->assertTrue(Schema::hasColumn('users', 'locale'));
        $this->assertTrue(Schema::hasColumn('users', 'timezone'));
        $this->assertTrue(Schema::hasColumn('users', 'status'));

        // Roll back the latest batch
        $rollbackExitCode = Artisan::call('migrate:rollback', ['--force' => true]);
        $this->assertSame(0, $rollbackExitCode, 'migrate:rollback must exit with code 0.');

        // Re-apply migrations
        $reapplyExitCode = Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(0, $reapplyExitCode, 'migrate must exit with code 0 upon re-applying.');

        // Verify all tables exist again
        foreach ($expectedTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Table {$table} must exist after re-applying migrations."
            );
        }
    }
}
