<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->string('trace_id', 64)->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_type', 50)->nullable();
            $table->string('subject_type', 100)->index();
            $table->string('subject_id', 100)->index();
            $table->string('action', 100)->index();
            $table->string('old_hash', 64)->nullable();
            $table->string('new_hash', 64)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            // Composite indexes for query patterns
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
