<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('identifier', 35)->unique(); // Format: {CODE}-{N}
            $table->unsignedInteger('sequence_number');
            $table->uuid('artifactable_id');
            $table->string('artifactable_type', 255);
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'sequence_number']);
            $table->index(['artifactable_id', 'artifactable_type']);
            // Sequence counter is unique per project — enforced by application with SELECT ... FOR UPDATE
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
