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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            // Case-sensitive idempotency key in binary collation
            $table->string('key', 255)->collation('utf8mb4_bin');
            // Principal / tenant identifier (0 indicates public / guest scope)
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('route', 255);
            $table->string('request_hash', 64);
            $table->smallInteger('response_code')->nullable();
            $table->json('response_headers')->nullable();
            $table->json('response_body')->nullable();
            $table->string('status', 30)->default('in_flight')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            // Compound unique constraint: scoped strictly by principal + operation/route + case-sensitive key
            $table->unique(['user_id', 'route', 'key'], 'idempotency_principal_route_key_unique');

            // Composite index for fast expiration cleanup and status queries
            $table->index(['expires_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
