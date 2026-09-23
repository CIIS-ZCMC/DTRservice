<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds receiver_by_default boolean flag to devices table.
     *
     * When true (default): the device will receive biometric template sync broadcasts.
     * When false: the device only sends data (attend-only mode, e.g. HRBLIZ terminals
     * that should not receive fingerprint/user provisioning commands).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('devices', 'receiver_by_default')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->boolean('receiver_by_default')->default(true)->after('is_hrbliz');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('receiver_by_default');
        });
    }
};
