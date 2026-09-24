<?php

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\DeviceCommandService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function () {
    if (!Schema::hasTable('devices')) {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_name')->nullable();
            $table->string('device_id')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('ip_address')->nullable();
            $table->integer('com_key')->default(0);
            $table->integer('soap_port')->default(80);
            $table->integer('udp_port')->default(4370);
            $table->boolean('is_active')->default(1);
            $table->boolean('is_registration')->default(0);
            $table->boolean('for_attendance')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('biometrics')) {
        Schema::create('biometrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->unique();
            $table->string('name')->nullable();
            $table->integer('privilege')->default(0);
            $table->longText('biometric')->nullable();
            $table->longText('face')->nullable();
            $table->longText('biophoto')->nullable();
            $table->string('name_with_biometric')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasColumn('devices', 'is_hrbliz')) {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('is_hrbliz')->default(false)->after('for_attendance');
        });
    }

    if (!Schema::hasColumn('devices', 'receiver_by_default')) {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('receiver_by_default')->default(true)->after('is_hrbliz');
        });
    }

    if (!Schema::hasColumn('biometrics', 'hrbliz_biometric_id')) {
        Schema::table('biometrics', function (Blueprint $table) {
            $table->unsignedBigInteger('hrbliz_biometric_id')->nullable()->after('biometric_id');
        });
    }

    app(DeviceCommandService::class)->clearCommands();
    Devices::query()->delete();
    Biometrics::query()->delete();

    Devices::create([
        'device_name' => 'Device 1 (Target)',
        'serial_number' => 'SYNC_SN_001',
        'ip_address' => '192.168.1.101',
        'is_active' => 1,
        'is_registration' => 1,
        'for_attendance' => 0,
        'is_hrbliz' => 0,
        'receiver_by_default' => 1,
    ]);
});

test('biometrics:sync-device strictly deletes unenrolled finger slots 0-9 and updates enrolled slot 6', function () {
    $templates = [
        ['Finger_ID' => '6', 'Size' => '1200', 'Valid' => '1', 'Template' => 'BASE64_TEMPLATE_FINGER_6'],
    ];

    $bio = Biometrics::create([
        'biometric_id' => 9901,
        'name' => 'Strict Sync User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    // Run sync command with --all-devices
    $this->artisan('biometrics:sync-device', ['--all-devices' => true, '--pin' => 9901])
        ->assertExitCode(0);

    $cmds = $commandService->getAllCommands('SYNC_SN_001');

    // Expected commands:
    // 1x DATA USER PIN=9901 ...
    // 9x DATA DELETE FINGERTMP PIN=9901 FID={0,1,2,3,4,5,7,8,9}
    // 1x DATA UPDATE fingertmp PIN=9901 FID=6 ...
    expect($cmds)->toHaveCount(11);

    // Check DATA USER
    $userCmd = $cmds[0]['command'];
    expect($userCmd)->toContain('DATA USER PIN=9901');
    expect($userCmd)->toContain('TZ=1');

    // Check Delete commands for slots 0..5, 7..9
    $deleteCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA DELETE FINGERTMP'));
    expect($deleteCmds)->toHaveCount(9);

    $deletedFids = [];
    foreach ($deleteCmds as $d) {
        if (preg_match('/FID=(\d+)/', $d['command'], $m)) {
            $deletedFids[] = (int)$m[1];
        }
    }
    sort($deletedFids);
    expect($deletedFids)->toBe([0, 1, 2, 3, 4, 5, 7, 8, 9]);

    // Check Update command for Finger 6
    $updateCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA UPDATE fingertmp'));
    expect($updateCmds)->toHaveCount(1);
    expect(array_values($updateCmds)[0]['command'])->toContain('FID=6');
    expect(array_values($updateCmds)[0]['command'])->toContain('BASE64_TEMPLATE_FINGER_6');
});

test('biometrics:sync-device preserves face and biophoto while cleaning unused finger slots', function () {
    $templates = [
        ['Finger_ID' => '3', 'Size' => '800', 'Valid' => '1', 'Template' => 'BASE64_TEMPLATE_FINGER_3'],
    ];

    $bio = Biometrics::create([
        'biometric_id' => 9902,
        'name' => 'Face And Finger User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
        'face' => json_encode(['PIN' => '9902', 'Size' => '2000', 'Valid' => '1', 'FaceData' => 'FACE_BASE64_DATA']),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', ['--all-devices' => true, '--pin' => 9902])
        ->assertExitCode(0);

    $cmds = $commandService->getAllCommands('SYNC_SN_001');

    $faceCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA UPDATE biodata'));
    expect($faceCmds)->toHaveCount(1);
    expect(array_values($faceCmds)[0]['command'])->toContain('PIN=9902');
    expect(array_values($faceCmds)[0]['command'])->toContain('FaceData=FACE_BASE64_DATA');

    // No delete command for face
    $faceDeleteCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DELETE') && str_contains($c['command'], 'biodata'));
    expect($faceDeleteCmds)->toHaveCount(0);
});

test('biometrics:sync-device logs push sync with BiometricID, Device Name, and Time pushed', function () {
    $today = now()->format('Y-m-d');
    $logFile = storage_path("logs/sync_pushed_{$today}.txt");
    if (file_exists($logFile)) {
        @unlink($logFile);
    }

    $templates = [
        ['Finger_ID' => '6', 'Size' => '1200', 'Valid' => '1', 'Template' => 'BASE64_TEMPLATE_FINGER_6'],
    ];

    $bio = Biometrics::create([
        'biometric_id' => 9904,
        'name' => 'Audit Log User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $this->artisan('biometrics:sync-device', ['--all-devices' => true, '--pin' => 9904])
        ->assertExitCode(0);

    expect(file_exists($logFile))->toBeTrue();
    $content = file_get_contents($logFile);
    expect($content)->toContain('PIN=9904');
    expect($content)->toContain('Audit Log User');
    expect($content)->toContain('Device 1 (Target)');
    expect($content)->toContain('SYNC_SN_001');
});

test('device command ACK logs confirmed sync to sync_ack log file', function () {
    $today = now()->format('Y-m-d');
    $logFile = storage_path("logs/sync_ack_{$today}.txt");
    if (file_exists($logFile)) {
        @unlink($logFile);
    }

    $commandService = app(DeviceCommandService::class);
    $cmd = $commandService->queueCommand('SYNC_SN_001', "DATA UPDATE fingertmp\tPIN=9905\tFID=6\tSize=500\tValid=1\tTMP=BASE64TMP");

    // Simulate Device sending ACK with return code 0 (success)
    $commandService->recordCommandAck($cmd['id'], 0);

    expect(file_exists($logFile))->toBeTrue();
    $content = file_get_contents($logFile);
    expect($content)->toContain('SUCCESS');
    expect($content)->toContain('PIN=9905');
    expect($content)->toContain('SYNC_SN_001');
    expect($content)->toContain('FINGERPRINT_UPDATE (FID 6)');
});

test('biometrics:command-status command displays real-time sync status overview', function () {
    $commandService = app(DeviceCommandService::class);
    $cmd1 = $commandService->queueCommand('SYNC_SN_001', "DATA UPDATE fingertmp\tPIN=9906\tFID=1\tSize=500\tValid=1\tTMP=TMP1");
    $cmd2 = $commandService->queueCommand('SYNC_SN_001', "DATA UPDATE fingertmp\tPIN=9906\tFID=2\tSize=500\tValid=1\tTMP=TMP2");

    $commandService->recordCommandAck($cmd1['id'], 0);

    $this->artisan('biometrics:command-status')
        ->expectsOutputToContain('DEVICE COMMAND QUEUE & SYNC STATUS')
        ->expectsOutputToContain('Total Commands')
        ->assertExitCode(0);
});

test('biometrics:sync-device with --no-clean does not generate DATA DELETE FINGERTMP', function () {
    $templates = [
        ['Finger_ID' => '6', 'Size' => '1200', 'Valid' => '1', 'Template' => 'BASE64_TEMPLATE_FINGER_6'],
    ];

    $bio = Biometrics::create([
        'biometric_id' => 9910,
        'name' => 'No Clean User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', [
        '--all-devices' => true,
        '--pin' => 9910,
        '--no-clean' => true,
    ])->assertExitCode(0);

    $cmds = $commandService->getAllCommands('SYNC_SN_001');

    // Expected: 1x DATA USER, 1x DATA UPDATE fingertmp, 0x DELETE
    $deleteCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA DELETE FINGERTMP'));
    expect($deleteCmds)->toBeEmpty();

    $updateCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA UPDATE fingertmp'));
    expect($updateCmds)->toHaveCount(1);
});

test('biometrics:sync-device for face-only user does not generate DATA DELETE FINGERTMP', function () {
    $bio = Biometrics::create([
        'biometric_id' => 9911,
        'name' => 'Face Only User',
        'privilege' => 0,
        'biometric' => null,
        'face' => json_encode(['PIN' => '9911', 'Size' => '2000', 'Valid' => '1', 'FaceData' => 'FACE_ONLY']),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', [
        '--all-devices' => true,
        '--pin' => 9911,
    ])->assertExitCode(0);

    $cmds = $commandService->getAllCommands('SYNC_SN_001');
    $deleteCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA DELETE FINGERTMP'));
    expect($deleteCmds)->toBeEmpty();

    $faceCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA UPDATE biodata'));
    expect($faceCmds)->toHaveCount(1);
});

test('biometrics:sync-device deduplicates devices with same serial number', function () {
    // Create duplicate device with same serial number
    Devices::create([
        'device_name' => 'Device 1 Duplicate',
        'serial_number' => 'SYNC_SN_001',
        'ip_address' => '192.168.1.102',
        'is_active' => 1,
        'is_registration' => 0,
    ]);

    $templates = [
        ['Finger_ID' => '6', 'Size' => '1200', 'Valid' => '1', 'Template' => 'BASE64_TEMPLATE_FINGER_6'],
    ];

    $bio = Biometrics::create([
        'biometric_id' => 9912,
        'name' => 'Dedup Test User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', [
        '--all-devices' => true,
        '--pin' => 9912,
    ])->assertExitCode(0);

    // Commands should only be queued ONCE for SYNC_SN_001 (11 commands, not 22)
    $cmds = $commandService->getAllCommands('SYNC_SN_001');
    expect($cmds)->toHaveCount(11);
});

test('biometrics:sync-device --all-devices skips HRBLIZ devices with receiver_by_default = 0', function () {
    // Create HRBLIZ device with receiver_by_default = 0
    Devices::create([
        'device_name' => 'HRBLIZ Gate Send-Only',
        'serial_number' => 'SN_HRBLIZ_NO_RECV',
        'ip_address' => '192.168.1.103',
        'is_active' => 1,
        'is_hrbliz' => 1,
        'receiver_by_default' => 0,
    ]);

    $templates = [
        ['Finger_ID' => '1', 'Size' => '1000', 'Valid' => '1', 'Template' => 'TMP_HRBLIZ_TEST'],
    ];

    Biometrics::create([
        'biometric_id' => 9913,
        'hrbliz_biometric_id' => 7713,
        'name' => 'HRBLIZ Test User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', [
        '--all-devices' => true,
        '--pin' => 9913,
    ])->assertExitCode(0);

    // Standard device (SYNC_SN_001) must receive commands
    $stdCmds = $commandService->getAllCommands('SYNC_SN_001');
    expect($stdCmds)->not->toBeEmpty();
    expect($stdCmds[0]['command'])->toContain('PIN=9913');

    // HRBLIZ device with receiver_by_default = 0 must receive ZERO commands
    $hrblizCmds = $commandService->getAllCommands('SN_HRBLIZ_NO_RECV');
    expect($hrblizCmds)->toBeEmpty();
});

test('biometrics:sync-device --all-devices runs on HRBLIZ device when receiver_by_default = 1 and throws hrbliz_biometric_id', function () {
    // Create HRBLIZ device with receiver_by_default = 1
    Devices::create([
        'device_name' => 'HRBLIZ Gate Receiving',
        'serial_number' => 'SN_HRBLIZ_RECV_1',
        'ip_address' => '192.168.1.104',
        'is_active' => 1,
        'is_hrbliz' => 1,
        'receiver_by_default' => 1,
    ]);

    $templates = [
        ['Finger_ID' => '1', 'Size' => '1000', 'Valid' => '1', 'Template' => 'TMP_HRBLIZ_TEST_1'],
    ];

    Biometrics::create([
        'biometric_id' => 9914,
        'hrbliz_biometric_id' => 7714,
        'name' => 'HRBLIZ Receiving User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', [
        '--all-devices' => true,
        '--pin' => 9914,
    ])->assertExitCode(0);

    // HRBLIZ device with receiver_by_default = 1 must receive commands using hrbliz_biometric_id (7714)
    $hrblizCmds = $commandService->getAllCommands('SN_HRBLIZ_RECV_1');
    expect($hrblizCmds)->not->toBeEmpty();
    expect($hrblizCmds[0]['command'])->toContain('DATA USER PIN=7714');
    expect($hrblizCmds[0]['command'])->not->toContain('PIN=9914');

    // Standard device (SYNC_SN_001) must receive commands using canonical biometric_id (9914)
    $stdCmds = $commandService->getAllCommands('SYNC_SN_001');
    expect($stdCmds)->not->toBeEmpty();
    expect($stdCmds[0]['command'])->toContain('DATA USER PIN=9914');
});

test('biometrics:sync-device <SERIAL_NUMBER> errors out and does not run when target device is HRBLIZ with receiver_by_default = 0', function () {
    Devices::create([
        'device_name' => 'HRBLIZ Direct Rejected',
        'serial_number' => 'SN_HRBLIZ_DIRECT_0',
        'ip_address' => '192.168.1.105',
        'is_active' => 1,
        'is_hrbliz' => 1,
        'receiver_by_default' => 0,
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', [
        'device_sn' => 'SN_HRBLIZ_DIRECT_0',
    ])
    ->expectsOutputToContain('Cannot sync to device [SN_HRBLIZ_DIRECT_0]')
    ->assertExitCode(1);

    expect($commandService->getAllCommands('SN_HRBLIZ_DIRECT_0'))->toBeEmpty();
});

test('biometrics:sync-device <SERIAL_NUMBER> successfully runs when target device is HRBLIZ with receiver_by_default = 1 and throws hrbliz_biometric_id', function () {
    Devices::create([
        'device_name' => 'HRBLIZ Direct Allowed',
        'serial_number' => 'SN_HRBLIZ_DIRECT_1',
        'ip_address' => '192.168.1.106',
        'is_active' => 1,
        'is_hrbliz' => 1,
        'receiver_by_default' => 1,
    ]);

    Biometrics::create([
        'biometric_id' => 9915,
        'hrbliz_biometric_id' => 7715,
        'name' => 'HRBLIZ Direct User',
        'privilege' => 0,
        'biometric' => json_encode([['Finger_ID' => '2', 'Size' => '500', 'Valid' => '1', 'Template' => 'TMP_DIR']]),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', [
        'device_sn' => 'SN_HRBLIZ_DIRECT_1',
        '--pin' => 9915,
    ])->assertExitCode(0);

    $cmds = $commandService->getAllCommands('SN_HRBLIZ_DIRECT_1');
    expect($cmds)->not->toBeEmpty();
    expect($cmds[0]['command'])->toContain('PIN=7715');
});

test('biometrics:sync-device --all-devices skips standard devices with receiver_by_default = 0', function () {
    Devices::create([
        'device_name' => 'Standard Send-Only',
        'serial_number' => 'SN_STD_NO_RECV',
        'ip_address' => '192.168.1.107',
        'is_active' => 1,
        'is_hrbliz' => 0,
        'receiver_by_default' => 0,
    ]);

    Biometrics::create([
        'biometric_id' => 9916,
        'name' => 'Standard User',
        'privilege' => 0,
        'biometric' => json_encode([['Finger_ID' => '0', 'Size' => '500', 'Valid' => '1', 'Template' => 'TMP_STD']]),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', [
        '--all-devices' => true,
        '--pin' => 9916,
    ])->assertExitCode(0);

    expect($commandService->getAllCommands('SN_STD_NO_RECV'))->toBeEmpty();
    expect($commandService->getAllCommands('SYNC_SN_001'))->not->toBeEmpty();
});

