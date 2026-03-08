<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'users',
        'projects',
        'api_tokens',
        'oauth_clients',
        'oauth_access_tokens',
        'oauth_authorization_codes',
        'artifacts',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('tenant_id')->after('id');
                $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign(["{$table}_tenant_id_foreign"]);
                $t->dropColumn('tenant_id');
            });
        }
    }
};
