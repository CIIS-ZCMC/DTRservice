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
        if (!Schema::hasTable('device_logs_hrbliz')) {
            Schema::create('device_logs_hrbliz', function (Blueprint $table) {
                $table->id();
                $table->string('biometric_id', 191);
                $table->string('name', 191);
                $table->date('dtr_date');
                $table->dateTime('date_time');
                $table->integer('status');
                $table->integer('is_Shifting')->default(0);
                $table->text('schedule')->nullable();
                $table->integer('active')->default(1)->comment('True if Existing Employee.');
                $table->string('device_name', 200)->nullable();
                $table->timestamps();

                $table->index('biometric_id');
                $table->index('dtr_date');
                $table->index('date_time');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_logs_hrbliz');
    }
};
