<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id');           // blocked item
            $table->string('item_type', 255);  // class name of blocked item
            $table->uuid('depends_on_id');     // blocking item
            $table->string('depends_on_type', 255); // class name of blocking item
            $table->timestamp('created_at')->nullable();
            // No updated_at

            $table->unique(['item_id', 'item_type', 'depends_on_id', 'depends_on_type'], 'item_dep_unique');
            $table->index(['item_id', 'item_type']);
            $table->index(['depends_on_id', 'depends_on_type']);
            // No direct FK on polymorphic columns — app handles referential integrity via observers
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_dependencies');
    }
};
