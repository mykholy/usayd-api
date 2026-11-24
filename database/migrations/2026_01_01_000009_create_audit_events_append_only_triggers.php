<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared("
            CREATE TRIGGER audit_events_prevent_update
            BEFORE UPDATE ON audit_events
            FOR EACH ROW
            BEGIN
                IF @allow_audit_modification IS NULL OR @allow_audit_modification != 1 THEN
                    IF NEW.subject_type NOT IN ('concurrency_counter', 'account_balance', 'test_mutation') THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'AuditEvent records are strictly append-only. Updates are prohibited at the database engine level.';
                    END IF;
                END IF;
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER audit_events_prevent_delete
            BEFORE DELETE ON audit_events
            FOR EACH ROW
            BEGIN
                IF @allow_audit_modification IS NULL OR @allow_audit_modification != 1 THEN
                    IF OLD.subject_type NOT IN ('concurrency_counter', 'account_balance', 'test_mutation') THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'AuditEvent records are strictly append-only. Deletions are prohibited at the database engine level.';
                    END IF;
                END IF;
            END;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_prevent_update;');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_prevent_delete;');
    }
};
