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
        if (Schema::hasTable('devices') && !Schema::hasColumn('devices', 'fp_version')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->string('fp_version', 10)->default('v10')->nullable()->after('serial_number');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'fp_version')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn('fp_version');
            });
        }
    }
};
