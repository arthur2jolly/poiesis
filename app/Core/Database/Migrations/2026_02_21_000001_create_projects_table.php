<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 25)->unique();
            $table->string('titre', 255);
            $table->text('description')->nullable();
            $table->json('modules')->default('[]');
            $table->timestamps();

            // Route model binding uses 'code' not 'id'
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
