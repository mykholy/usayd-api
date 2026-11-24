<?php

namespace Tests\Feature\Queue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_queue_default_configuration_and_drivers(): void
    {
        $defaultConnection = config('queue.default');
        $this->assertContains($defaultConnection, ['redis', 'sync']);

        $failedDriver = config('queue.failed.driver');
        $this->assertSame('database-uuids', $failedDriver);

        $failedDb = config('queue.failed.database');
        $this->assertSame('mysql', $failedDb);
    }

    public function test_failed_jobs_table_exists_and_uses_innodb(): void
    {
        $this->assertTrue(Schema::hasTable('failed_jobs'), 'failed_jobs table must exist.');

        $databaseName = DB::connection()->getDatabaseName();
        $tableInfo = DB::selectOne('
            SELECT ENGINE, TABLE_COLLATION
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = "failed_jobs"
        ', [$databaseName]);

        $this->assertNotNull($tableInfo);
        $this->assertSame('InnoDB', $tableInfo->ENGINE);
        $this->assertMatchesRegularExpression('/^utf8mb4_/i', $tableInfo->TABLE_COLLATION);
    }

    public function test_queue_partitions_are_properly_configured(): void
    {
        $connections = config('queue.connections');

        $this->assertArrayHasKey('redis', $connections);
        $this->assertArrayHasKey('redis-high', $connections);
        $this->assertArrayHasKey('redis-notifications', $connections);
        $this->assertArrayHasKey('redis-reports', $connections);

        $this->assertSame('high', $connections['redis-high']['queue']);
        $this->assertSame('notifications', $connections['redis-notifications']['queue']);
        $this->assertSame('reports', $connections['redis-reports']['queue']);
    }

    public function test_horizon_configuration_defines_supervisors_and_partitions(): void
    {
        $horizon = config('horizon');
        $this->assertNotNull($horizon);

        $defaults = $horizon['defaults']['supervisor-1'] ?? null;
        $this->assertNotNull($defaults);

        $expectedQueues = ['high', 'default', 'notifications', 'reports'];
        $this->assertSame($expectedQueues, $defaults['queue']);
        $this->assertSame(3, $defaults['tries']);
        $this->assertSame([5, 15, 60], $defaults['backoff']);
    }
}
