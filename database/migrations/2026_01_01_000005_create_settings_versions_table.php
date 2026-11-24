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
        Schema::create('settings_versions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->string('namespace', 100)->index();
            $table->string('scope', 100)->default('global')->index();
            $table->string('schema_version', 20)->default('1.0');
            $table->json('settings');
            $table->string('status', 30)->default('draft')->index();
            $table->string('checksum', 64);
            $table->timestamp('effective_at')->nullable()->index();
            $table->unsignedBigInteger('proposed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            // Composite index for active configuration lookup
            $table->index(['namespace', 'scope', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings_versions');
    }
};
