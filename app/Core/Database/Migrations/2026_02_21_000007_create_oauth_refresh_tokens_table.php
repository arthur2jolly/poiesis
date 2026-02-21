<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('access_token_id');
            $table->string('token', 255)->unique(); // SHA-256 hash
            $table->timestamp('expires_at');
            $table->boolean('revoked')->default(false);
            $table->timestamp('created_at')->nullable();
            // No updated_at

            $table->foreign('access_token_id')->references('id')->on('oauth_access_tokens')->cascadeOnDelete();
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
    }
};
