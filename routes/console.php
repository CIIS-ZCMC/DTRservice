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

// 6-week biometric device attendance logs clearance (checks daily at 23:55)
// Automatically pulls and verifies any unsaved punches to DB before issuing CLEAR LOG
// Only targets devices that have not been cleared in 42+ days (6 weeks)
Schedule::command('devices:clear-logs --all --catch-up --older-than=42 --force --method=both')
    ->dailyAt('23:55')
    ->description('Verified clearing of biometric devices with attendance logs older than 6 weeks (42 days)')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/device_clear.log'));

// Monthly database pruning of device_logs older than 1 year (with automatic compressed archive)
Schedule::command('device-logs:prune --years=1 --archive --force')
    ->monthlyOn(1, '01:00')
    ->description('Monthly pruning of database device logs older than 1 year')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/device_logs_prune.log'));
