<?php

use App\Services\DeviceCommandService;

test('artisan biometrics:resolve-sent successfully resolves stuck SENT commands and prunes files', function () {
    $testDir = storage_path('framework/testing/device_queues');
    $service = new DeviceCommandService($testDir);
    $service->clearCommands();

    $devFile = $service->getDeviceQueueFile('SN_ARTISAN_TEST');

    $rec1 = [
        'id' => 501,
        'device_sn' => 'SN_ARTISAN_TEST',
        'command' => 'DATA USER PIN=501',
        'status' => 'SENT',
        'return_code' => null,
        'created_at' => now()->subDays(2)->toDateTimeString(),
        'updated_at' => now()->subDays(2)->toDateTimeString(),
    ];

    file_put_contents($devFile, json_encode($rec1) . "\n");
    expect(file_exists($devFile))->toBeTrue();

    $this->artisan('biometrics:resolve-sent', ['--days' => 3, '--mode' => 'within', '--device' => 'SN_ARTISAN_TEST'])
        ->expectsOutputToContain('Successfully changed 1 stuck SENT command(s) to SUCCESS!')
        ->assertExitCode(0);

    // The file should now be deleted because its only command reached SUCCESS
    expect(file_exists($devFile))->toBeFalse();

    $service->clearCommands();
});
