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

test('biometrics:sync-device with --no-clean skips delete commands', function () {
    $templates = [
        ['Finger_ID' => '6', 'Size' => '1200', 'Valid' => '1', 'Template' => 'BASE64_TEMPLATE_FINGER_6'],
    ];

    $bio = Biometrics::create([
        'biometric_id' => 9903,
        'name' => 'No Clean User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $this->artisan('biometrics:sync-device', ['--all-devices' => true, '--pin' => 9903, '--no-clean' => true])
        ->assertExitCode(0);

    $cmds = $commandService->getAllCommands('SYNC_SN_001');

    // Should only have 1x USER and 1x UPDATE fingertmp (no delete commands)
    $deleteCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA DELETE'));
    expect($deleteCmds)->toBeEmpty();
    expect($cmds)->toHaveCount(2);
});
