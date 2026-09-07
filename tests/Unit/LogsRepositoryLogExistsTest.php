<?php

use App\Contracts\DeviceRepositoryInterface;
use App\Repositories\LogsRepository;

uses(Tests\TestCase::class);

test('logExists detects existing log in device_logs files', function () {
    $deviceRepo = mock(DeviceRepositoryInterface::class);
    $logsRepo = new LogsRepository($deviceRepo);

    $testFile = storage_path('logs/device_logs_test_file.log');
    
    $sampleLine = '[2026-09-07 10:00:00] local.INFO: Device log entry {"biometric_id":12345,"dtr_date":"2026-09-07","name":"Juan Dela Cruz","dtr_time":"08:30:00","dtr_type":"0","device_name":"Main Gate","ip_address":"192.168.1.50","logged_at":"2026-09-07T08:30:00.000000Z","raw_line":"12345\t2026-09-07 08:30:00\t0"}';
    file_put_contents($testFile, $sampleLine . PHP_EOL);

    try {
        expect($logsRepo->logExists(12345, '2026-09-07 08:30:00'))->toBeTrue();
        expect($logsRepo->logExists(12345, '2026-09-07 08:30:01'))->toBeFalse();
        expect($logsRepo->logExists(99999, '2026-09-07 08:30:00'))->toBeFalse();
    } finally {
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }
});
