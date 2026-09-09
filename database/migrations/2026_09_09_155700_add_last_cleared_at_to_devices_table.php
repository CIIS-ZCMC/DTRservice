<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('devices') && !Schema::hasColumn('devices', 'last_cleared_at')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->timestamp('last_cleared_at')->nullable()->after('last_seen_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'last_cleared_at')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn('last_cleared_at');
            });
        }
    }
};
