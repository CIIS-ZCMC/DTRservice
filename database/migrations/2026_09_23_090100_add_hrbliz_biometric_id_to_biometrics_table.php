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
        if (Schema::hasTable('biometrics') && !Schema::hasColumn('biometrics', 'hrbliz_biometric_id')) {
            Schema::table('biometrics', function (Blueprint $table) {
                $table->integer('hrbliz_biometric_id')->nullable()->index()->after('biometric_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('biometrics') && Schema::hasColumn('biometrics', 'hrbliz_biometric_id')) {
            Schema::table('biometrics', function (Blueprint $table) {
                $table->dropColumn('hrbliz_biometric_id');
            });
        }
    }
};
