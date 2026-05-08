<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POIESIS-107 follow-up — backfill started_at on artifacts already in `open`.
 *
 * The original migration (2026_05_08_120000) added the column but no backfill,
 * so any project with an active sprint at deploy time silently demotes its
 * open items from "in progress" (spinner) to "ready" (slate ring) on the
 * Scrum board. Run a one-shot UPDATE so the indicator stays consistent with
 * the pre-deploy visual state.
 *
 * Strictly idempotent: only touches rows still NULL. Items in draft or
 * closed are left alone. Future transitions are owned by the Eloquent hook.
 */
return new class extends Migration
{
    public function up(): void
    {
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
        // Backfill is a one-shot data correction; rolling it back would lose
        // information without recovering the prior NULL state in any useful
        // way (the hook would re-fill on the next status change anyway).
        // Intentional no-op — the column itself is dropped by the previous
        // migration's down().
    }
};
