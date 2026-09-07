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
    } else {
        if (!Schema::hasColumn('biometrics', 'face')) {
            Schema::table('biometrics', function (Blueprint $table) {
                $table->longText('face')->nullable();
            });
        }
        if (!Schema::hasColumn('biometrics', 'biophoto')) {
            Schema::table('biometrics', function (Blueprint $table) {
                $table->longText('biophoto')->nullable();
            });
        }
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

    Devices::create([
        'device_name' => 'Device 2 (Target)',
        'serial_number' => 'SYNC_SN_002',
        'ip_address' => '192.168.1.102',
        'is_active' => 1,
        'is_registration' => 0,
        'for_attendance' => 1,
    ]);
});

test('creating Biometrics record automatically queues DATA USER and templates to all devices', function () {
    $templates = [
        ['Finger_ID' => '5', 'Size' => '1200', 'Valid' => '1', 'Template' => 'BASE64_TEMPLATE_5'],
    ];

    $bio = Biometrics::create([
        'biometric_id' => 7711,
        'name' => 'Auto Sync User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $commandService = app(DeviceCommandService::class);
    $cmds1 = $commandService->getAllCommands('SYNC_SN_001');
    $cmds2 = $commandService->getAllCommands('SYNC_SN_002');

    expect($cmds1)->toHaveCount(2);
    expect($cmds2)->toHaveCount(2);

    expect($cmds1[0]['command'])->toContain('DATA USER PIN=7711');
    expect($cmds1[0]['command'])->toContain('Name=Auto Sync User');
    expect($cmds1[1]['command'])->toContain('DATA UPDATE fingertmp');
    expect($cmds1[1]['command'])->toContain('FID=5');
});

test('updating name on Biometrics model automatically queues updated DATA USER command', function () {
    $bio = Biometrics::create([
        'biometric_id' => 7712,
        'name' => 'Initial Name',
        'privilege' => 0,
        'biometric' => null,
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $bio->update(['name' => 'Updated Full Name']);

    $cmds = $commandService->getAllCommands('SYNC_SN_001');
    expect($cmds)->toHaveCount(1);
    expect($cmds[0]['command'])->toContain('DATA USER PIN=7712');
    expect($cmds[0]['command'])->toContain('Name=Updated Full Name');
});

test('modifying templates in Biometrics model queues DATA UPDATE for new fingers and DATA DELETE for removed fingers', function () {
    $initialTemplates = [
        ['Finger_ID' => '1', 'Size' => '500', 'Valid' => '1', 'Template' => 'TMP_1'],
        ['Finger_ID' => '2', 'Size' => '500', 'Valid' => '1', 'Template' => 'TMP_2'],
    ];

    $bio = Biometrics::create([
        'biometric_id' => 7713,
        'name' => 'Fingerprint Edit User',
        'privilege' => 0,
        'biometric' => json_encode($initialTemplates),
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    // Remove FID 1, update FID 2, add FID 6
    $updatedTemplates = [
        ['Finger_ID' => '2', 'Size' => '600', 'Valid' => '1', 'Template' => 'TMP_2_MODIFIED'],
        ['Finger_ID' => '6', 'Size' => '700', 'Valid' => '1', 'Template' => 'TMP_6_NEW'],
    ];

    $bio->update(['biometric' => json_encode($updatedTemplates)]);

    $cmds = $commandService->getAllCommands('SYNC_SN_001');

    // Should have 1 delete command (FID 1) and 2 update commands (FID 2, FID 6)
    $deleteCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA DELETE FINGERTMP'));
    $updateCmds = array_filter($cmds, fn($c) => str_contains($c['command'], 'DATA UPDATE fingertmp'));

    expect($deleteCmds)->toHaveCount(1);
    expect(array_values($deleteCmds)[0]['command'])->toContain('FID=1');
    expect($updateCmds)->toHaveCount(2);
});

test('deleting Biometrics record automatically queues DATA DELETE USER to all devices', function () {
    $bio = Biometrics::create([
        'biometric_id' => 7714,
        'name' => 'Deleted User',
        'privilege' => 0,
        'biometric' => null,
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $bio->delete();

    $cmds1 = $commandService->getAllCommands('SYNC_SN_001');
    $cmds2 = $commandService->getAllCommands('SYNC_SN_002');

    expect($cmds1)->toHaveCount(1);
    expect($cmds2)->toHaveCount(1);

    expect($cmds1[0]['command'])->toBe("DATA DELETE USER PIN=7714");
    expect($cmds2[0]['command'])->toBe("DATA DELETE USER PIN=7714");
});

test('updating face or biophoto on Biometrics model automatically queues update commands to all devices', function () {
    $bio = Biometrics::create([
        'biometric_id' => 7715,
        'name' => 'Face User',
        'privilege' => 0,
        'biometric' => null,
    ]);

    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    $bio->update([
        'face' => json_encode(['Size' => '1500', 'Valid' => '1', 'FaceData' => 'TEST_FACE']),
    ]);

    $cmds1 = $commandService->getAllCommands('SYNC_SN_001');
    $faceCmds = array_filter($cmds1, fn($c) => str_contains($c['command'], 'DATA UPDATE biodata'));
    expect($faceCmds)->toHaveCount(1);
    expect(array_values($faceCmds)[0]['command'])->toContain('PIN=7715');

    $commandService->clearCommands();

    $bio->update([
        'biophoto' => json_encode(['FileName' => '7715.jpg', 'Size' => '500', 'Content' => 'BASE64_PHOTO']),
    ]);

    $cmdsPhoto = $commandService->getAllCommands('SYNC_SN_001');
    $photoCmds = array_filter($cmdsPhoto, fn($c) => str_contains($c['command'], 'DATA UPDATE biophoto'));
    expect($photoCmds)->toHaveCount(1);
    expect(array_values($photoCmds)[0]['command'])->toContain('PIN=7715');
});

