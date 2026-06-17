<?php

use App\Console\Commands\SyncBiometricLogs;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule biometric sync every 5 minutes
Schedule::command('biometric:sync')->everyFiveMinutes()
    ->description('Sync time logs from biometric devices')
    ->withoutOverlapping();
