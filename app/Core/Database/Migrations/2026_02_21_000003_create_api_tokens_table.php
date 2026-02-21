<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name', 255);
            $table->string('token', 255)->unique(); // SHA-256 hash of raw token
            $table->timestamp('expires_at')->nullable(); // null = never expires
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();
            // No updated_at — tokens are immutable; only last_used_at is updated

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
