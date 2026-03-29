<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_columns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('board_id');
            $table->string('name', 255);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('limit_warning')->nullable();
            $table->unsignedInteger('limit_hard')->nullable();
            $table->timestamps();

            $table->foreign('board_id')->references('id')->on('kanban_boards')->cascadeOnDelete();
            $table->index('board_id');
            $table->index(['board_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_columns');
    }
};
