<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('ready');
            $table->index('started_at');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('estimation_temps');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['started_at']);
            $table->dropColumn('started_at');
        });

        Schema::table('stories', function (Blueprint $table): void {
            $table->dropIndex(['started_at']);
            $table->dropColumn('started_at');
        });
    }
};
