<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_authorization_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('oauth_client_id');
            $table->uuid('user_id');
            $table->string('code', 255)->unique();
            $table->string('redirect_uri', 2048);
            $table->json('scopes')->nullable();
            $table->string('code_challenge', 255)->nullable(); // PKCE
            $table->string('code_challenge_method', 10)->nullable(); // S256
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
            // Authorization codes are short-lived; no updated_at needed

            $table->foreign('oauth_client_id')->references('id')->on('oauth_clients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_authorization_codes');
    }
};
