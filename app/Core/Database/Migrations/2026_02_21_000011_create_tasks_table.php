<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('story_id')->nullable();
            $table->string('titre', 255);
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->string('nature', 20)->nullable();
            $table->string('statut', 20)->default('draft');
            $table->string('priorite', 20)->default('moyenne');
            $table->unsignedInteger('ordre')->nullable();
            $table->unsignedInteger('estimation_temps')->nullable(); // minutes
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            // ON DELETE CASCADE: child tasks are deleted when parent story is deleted
            // Standalone tasks have story_id = NULL
            $table->foreign('story_id')->references('id')->on('stories')->cascadeOnDelete();
            $table->index('project_id');
            $table->index('story_id');
            $table->index('type');
            $table->index('nature');
            $table->index('statut');
            $table->index('priorite');
            $table->index('ordre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
