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
        if (! Schema::hasColumn('scrum_sprint_items', 'tenant_id')) {
            Schema::table('scrum_sprint_items', function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable()->after('id');
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index('tenant_id');
            });
        }

        $tenantBySprint = DB::table('scrum_sprints')
            ->pluck('tenant_id', 'id');

        DB::table('scrum_sprint_items')
            ->whereNull('tenant_id')
            ->orderBy('id')
            ->each(function (object $item) use ($tenantBySprint): void {
                $tenantId = $tenantBySprint[$item->sprint_id] ?? null;
                if ($tenantId === null) {
                    return;
                }

                DB::table('scrum_sprint_items')
                    ->where('id', $item->id)
                    ->update(['tenant_id' => $tenantId]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('scrum_sprint_items', 'tenant_id')) {
            return;
        }

        Schema::table('scrum_sprint_items', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
