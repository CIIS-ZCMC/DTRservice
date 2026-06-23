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
