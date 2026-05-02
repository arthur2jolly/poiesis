<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrum_columns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('project_id');
            $table->string('name');
            $table->unsignedInteger('position');
            $table->unsignedInteger('limit_warning')->nullable();
            $table->unsignedInteger('limit_hard')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            $table->index('tenant_id');
            $table->index('project_id');
            $table->unique(['project_id', 'position'], 'scrum_columns_project_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrum_columns');
    }
};
