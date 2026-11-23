<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MysqlStrictConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_connection_is_mysql_8_4(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $this->assertSame('mysql', $driver, 'Active database connection must strictly be MySQL.');

        $version = DB::selectOne('SELECT VERSION() as version')->version;
        $this->assertNotEmpty($version);
    }

    public function test_mysql_strict_sql_modes_are_enforced(): void
    {
        $sqlMode = DB::selectOne('SELECT @@session.sql_mode as mode')->mode;
        $modes = array_map('trim', explode(',', $sqlMode));

        $requiredModes = [
            'ONLY_FULL_GROUP_BY',
            'STRICT_TRANS_TABLES',
            'NO_ZERO_IN_DATE',
            'NO_ZERO_DATE',
            'ERROR_FOR_DIVISION_BY_ZERO',
            'NO_ENGINE_SUBSTITUTION',
        ];

        foreach ($requiredModes as $mode) {
            $this->assertContains(
                $mode,
                $modes,
                "MySQL session SQL mode must enforce {$mode}."
            );
        }
    }

    public function test_negative_invalid_zero_date_is_rejected_under_strict_mode(): void
    {
        $this->expectException(QueryException::class);

        // Attempting to insert an invalid zero-date must fail under STRICT_TRANS_TABLES + NO_ZERO_DATE
        DB::statement("
            INSERT INTO audit_events (subject_type, subject_id, action, created_at)
            VALUES ('test', '1', 'test_action', '0000-00-00 00:00:00')
        ");
    }

    public function test_negative_non_aggregated_group_by_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        // Attempting to select a non-aggregated column not in GROUP BY must fail under ONLY_FULL_GROUP_BY
        DB::statement('
            SELECT id, action, subject_type
            FROM audit_events
            GROUP BY action
        ');
    }
}
