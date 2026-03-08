<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropUnique(['identifier']);
            $table->unique(['tenant_id', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'identifier']);
            $table->unique('identifier');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->unique('code');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name']);
            $table->index('name');
        });
    }
};
