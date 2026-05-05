<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrum_item_placements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sprint_item_id');
            $table->uuid('column_id');
            $table->unsignedInteger('position');
            $table->timestamp('updated_at')->nullable();

            $table->foreign('sprint_item_id')->references('id')->on('scrum_sprint_items')->cascadeOnDelete();
            $table->foreign('column_id')->references('id')->on('scrum_columns')->cascadeOnDelete();

            $table->unique('sprint_item_id', 'scrum_item_placements_sprint_item_unique');
            $table->index('column_id');
            $table->index(['column_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrum_item_placements');
    }
};
