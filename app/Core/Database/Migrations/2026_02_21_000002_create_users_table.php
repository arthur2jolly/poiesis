<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->timestamps();

            // Index for Artisan user lookup commands
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
