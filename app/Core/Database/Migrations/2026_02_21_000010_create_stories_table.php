<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('epic_id');
            $table->string('titre', 255);
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->string('nature', 20)->nullable();
            $table->string('statut', 20)->default('draft');
            $table->string('priorite', 20)->default('moyenne');
            $table->unsignedInteger('ordre')->nullable();
            $table->unsignedInteger('story_points')->nullable();
            $table->string('reference_doc', 2048)->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->foreign('epic_id')->references('id')->on('epics')->cascadeOnDelete();
            $table->index('epic_id');
            $table->index('type');
            $table->index('nature');
            $table->index('statut');
            $table->index('priorite');
            $table->index('ordre');
            // MariaDB does not support GIN indexes natively for JSON.
            // Tag filtering uses JSON_CONTAINS at the application level.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
