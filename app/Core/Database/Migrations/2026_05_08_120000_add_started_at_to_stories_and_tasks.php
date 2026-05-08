<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // POIESIS-107 backfill: every artifact already in `open` at deploy
        // time keeps showing the in-progress signal. Without this, the new
        // task-status-indicator would silently demote every existing open
        // item from spinner ("in progress") to slate ring ("ready"), which
        // is a visible UX regression for any project with active sprints.
        // Use updated_at as the best-available proxy for "when did this
        // item start"; created_at as a fallback for safety.
        DB::statement(
            'UPDATE stories SET started_at = COALESCE(updated_at, created_at) '
            ."WHERE statut = 'open' AND started_at IS NULL"
        );
        DB::statement(
            'UPDATE tasks SET started_at = COALESCE(updated_at, created_at) '
            ."WHERE statut = 'open' AND started_at IS NULL"
        );
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
