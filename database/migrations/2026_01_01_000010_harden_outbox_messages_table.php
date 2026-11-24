<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Hardens outbox_messages table with database-enforced idempotency and worker recovery tracking.
     */
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->string('idempotency_key', 150)->nullable()->after('event_type');
            $table->timestamp('locked_at')->nullable()->after('scheduled_at');

            // Database-enforced idempotency unique index (nulls allowed, unique strings enforced)
            $table->unique('idempotency_key', 'uniq_outbox_idempotency_key');

            // Index for stale processing recovery
            $table->index(['status', 'locked_at'], 'idx_outbox_status_locked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropUnique('uniq_outbox_idempotency_key');
            $table->dropIndex('idx_outbox_status_locked_at');
            $table->dropColumn(['idempotency_key', 'locked_at']);
        });
    }
};
