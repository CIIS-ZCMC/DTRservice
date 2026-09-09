<?php

use App\Console\Commands\ProcessDailyTimeRecords;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule DTR processing every 5 minutes
Schedule::command('dtr:process --all')->everyFiveMinutes()
    ->description('Process daily time records for all employees')
    ->withoutOverlapping();

// Primary weekly device attendance logs wipe (every Sunday at 23:55)
// Automatically pulls and verifies any unsaved punches to DB before issuing CLEAR LOG
Schedule::command('devices:clear-logs --all --force --method=both')
    ->weeklyOn(0, '23:55')
    ->description('Weekly verified clearing of biometric device attendance logs')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/device_clear.log'));

// Daily catch-up for devices that were offline during Sunday's run and haven't been cleared in 7+ days
Schedule::command('devices:clear-logs --all --catch-up --older-than=7 --force --method=both')
    ->dailyAt('12:00')
    ->description('Catch-up clearing for reconnected biometric devices')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/device_clear.log'));

// Monthly database pruning of device_logs older than 1 year (with automatic compressed archive)
Schedule::command('device-logs:prune --years=1 --archive --force')
    ->monthlyOn(1, '01:00')
    ->description('Monthly pruning of database device logs older than 1 year')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/device_logs_prune.log'));
