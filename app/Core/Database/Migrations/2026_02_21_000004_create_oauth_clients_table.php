<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('name', 255);
            $table->string('client_id', 255)->unique();
            $table->string('client_secret', 255)->nullable(); // public clients have no secret
            $table->json('redirect_uris');
            $table->json('grant_types')->default('["authorization_code"]');
            $table->json('scopes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
