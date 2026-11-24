<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enforces database-level immutability on audit_events via MySQL triggers.
     * Rejects all UPDATE and DELETE operations regardless of Eloquent, QueryBuilder, or raw SQL origin.
     */
    public function up(): void
    {
        // Only attach triggers on MySQL/MariaDB connections
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("
                CREATE TRIGGER audit_events_prevent_update
                BEFORE UPDATE ON audit_events
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'AuditEvent records are immutable and cannot be updated at the database level.';
                END;
            ");

            DB::unprepared("
                CREATE TRIGGER audit_events_prevent_delete
                BEFORE DELETE ON audit_events
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'AuditEvent records are immutable and cannot be deleted at the database level.';
                END;
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_prevent_update;');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_prevent_delete;');
        }
    }
};
