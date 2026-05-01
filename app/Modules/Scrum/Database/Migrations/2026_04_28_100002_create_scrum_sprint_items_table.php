<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrum_sprint_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sprint_id');
            $table->uuid('artifact_id');
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('added_at')->useCurrent();

            $table->foreign('sprint_id')->references('id')->on('scrum_sprints')->cascadeOnDelete();
            $table->foreign('artifact_id')->references('id')->on('artifacts')->cascadeOnDelete();
            $table->unique('artifact_id');
            $table->index('sprint_id');
            $table->index(['sprint_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrum_sprint_items');
    }
};
