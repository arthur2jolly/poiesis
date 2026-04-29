<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->integer('rank')->nullable()->after('ordre');
            $table->boolean('ready')->default(false)->after('rank');
        });
    }

    public function down(): void
    {
        Schema::table('stories', fn ($t) => $t->dropColumn(['rank', 'ready']));
    }
};
