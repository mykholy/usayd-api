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
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(false)->index();
            $table->json('rules')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
