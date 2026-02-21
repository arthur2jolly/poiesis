<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('oauth_client_id');
            $table->uuid('user_id');
            $table->string('token', 255)->unique(); // SHA-256 hash
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
            // No updated_at

            $table->foreign('oauth_client_id')->references('id')->on('oauth_clients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_access_tokens');
    }
};
