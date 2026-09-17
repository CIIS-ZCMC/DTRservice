<?php

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\BiometricSyncService;
use App\Services\DeviceCommandService;
use App\Services\ZkPushParser;
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
            $table->string('fp_version')->default('v10')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasColumn('devices', 'fp_version')) {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('fp_version')->default('v10')->nullable();
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

    Devices::query()->delete();
    Biometrics::query()->delete();
});

test('ZkPushParser::resolveEmployeePin prioritizes PIN2 over internal terminal PIN', function () {
    // Normal v10 push: only PIN provided
    expect(ZkPushParser::resolveEmployeePin(['PIN' => '493']))->toBe(493);

    // v9 / older firmware push: PIN is sequential slot (2842), PIN2 is employee badge ID (493)
    expect(ZkPushParser::resolveEmployeePin(['PIN' => '2842', 'PIN2' => '493']))->toBe(493);

    // Compound push parsed line
    $parsed = ZkPushParser::parseKeyValues("USER PIN=2842\tPIN2=493\tName=Cardo Dalisay\tPri=0");
    expect(ZkPushParser::resolveEmployeePin($parsed[0]))->toBe(493);

    // Fallbacks
    expect(ZkPushParser::resolveEmployeePin(['user_id' => '101']))->toBe(101);
    expect(ZkPushParser::resolveEmployeePin(['pin' => '202']))->toBe(202);
});

test('Biometrics::getTemplateAlgorithm distinguishes v10 and v9 templates', function () {
    // v10 templates: starts with SIl..., contains UzIx
    $v10Short = 'SIlTUzIx' . str_repeat('A', 200);
    expect(Biometrics::getTemplateAlgorithm($v10Short))->toBe('v10');

    // v9 template with marker
    $v9Template = 'V9_' . str_repeat('C', 200);
    expect(Biometrics::getTemplateAlgorithm($v9Template))->toBe('v9');

    // General templates default to fleet standard v10
    expect(Biometrics::getTemplateAlgorithm('GENERAL_SAMPLE_TMP'))->toBe('v10');
});

test('Biometrics model stores both v10 and v9 templates concurrently and filters by algorithm', function () {
    $user = Biometrics::create([
        'biometric_id' => 493,
        'name' => 'Cardo Dalisay',
        'privilege' => 0,
        'biometric' => null,
    ]);

    $v10Tmpl = 'SIlTUzIx' . str_repeat('X', 500);
    $v9Tmpl = 'V9_' . str_repeat('Y', 450);

    // 1. Enroll v10 template on finger 0
    $user->addOrUpdateFingerprint(0, strlen($v10Tmpl), 1, $v10Tmpl, 'v10');
    $user->save();

    expect($user->getTemplatesForAlgorithm('v10'))->toHaveCount(1);
    expect($user->getTemplatesForAlgorithm('v9'))->toHaveCount(0);

    // 2. Enroll v9 template on finger 0 (dual algorithm enrollment)
    $user->addOrUpdateFingerprint(0, strlen($v9Tmpl), 1, $v9Tmpl, 'v9');
    $user->save();

    // Both algorithms should be stored
    $decoded = json_decode($user->biometric, true);
    expect($decoded)->toHaveCount(2);

    $v10List = $user->getTemplatesForAlgorithm('v10');
    $v9List = $user->getTemplatesForAlgorithm('v9');

    expect($v10List)->toHaveCount(1);
    expect($v10List[0]['Template'])->toBe($v10Tmpl);

    expect($v9List)->toHaveCount(1);
    expect($v9List[0]['Template'])->toBe($v9Tmpl);
});

test('syncUserToAll outputs dual PIN (PIN and PIN2) and maintains Grp=1 & TZ=1', function () {
    Devices::create([
        'device_name' => 'V9 Old Terminal',
        'serial_number' => 'V9_OLD_SN',
        'ip_address' => '192.168.5.201',
        'is_active' => 1,
        'fp_version' => 'v9',
    ]);

    Devices::create([
        'device_name' => 'V10 New Terminal',
        'serial_number' => 'V10_NEW_SN',
        'ip_address' => '192.168.5.202',
        'is_active' => 1,
        'fp_version' => 'v10',
    ]);

    $syncService = app(BiometricSyncService::class);
    $commandService = app(DeviceCommandService::class);

    // Clear any existing commands
    $commandService->clearCommands();

    // User enrolling on a terminal sending PIN2=493
    $queued = $syncService->syncUserToAll(null, [
        'PIN' => '2842',
        'PIN2' => '493',
        'Name' => 'Cardo Dalisay',
        'Pri' => '0',
    ]);

    expect($queued)->toBe(2);

    $cmdsV9 = $commandService->getPendingCommands('V9_OLD_SN', 10);
    $cmdsV10 = $commandService->getPendingCommands('V10_NEW_SN', 10);

    expect($cmdsV9)->not->toBeEmpty();
    expect($cmdsV10)->not->toBeEmpty();

    // Verify both commands contain PIN=493\tPIN2=493 and Grp=1\tTZ=1
    expect($cmdsV9[0]['command'])->toContain("PIN=493\tPIN2=493");
    expect($cmdsV9[0]['command'])->toContain("Grp=1\tTZ=1");

    expect($cmdsV10[0]['command'])->toContain("PIN=493\tPIN2=493");
    expect($cmdsV10[0]['command'])->toContain("Grp=1\tTZ=1");
});

test('generateUserProvisionCommands routes templates according to target device algorithm', function () {
    $v9Device = Devices::create([
        'device_name' => 'Attendance - old device',
        'serial_number' => 'BRMC231660003',
        'ip_address' => '192.168.5.215',
        'is_active' => 1,
        'fp_version' => 'v9',
    ]);

    $v10Device = Devices::create([
        'device_name' => 'ATTENDANCE 161',
        'serial_number' => 'UCR6254000009',
        'ip_address' => '192.168.5.161',
        'is_active' => 1,
        'fp_version' => 'v10',
    ]);

    $user = Biometrics::create([
        'biometric_id' => 493,
        'name' => 'Cardo Dalisay',
        'privilege' => 0,
        'biometric' => null,
    ]);

    $v10Tmpl = 'SIlTUzIx' . str_repeat('V10_', 200);
    $v9Tmpl = 'V9_' . str_repeat('V9__', 100);

    $user->addOrUpdateFingerprint(3, strlen($v10Tmpl), 1, $v10Tmpl, 'v10');
    $user->addOrUpdateFingerprint(3, strlen($v9Tmpl), 1, $v9Tmpl, 'v9');
    $user->save();

    $syncService = app(BiometricSyncService::class);

    // Commands generated for v9 device
    $cmdsForV9 = $syncService->generateUserProvisionCommands($user, false, $v9Device);
    $v9TmplCmds = array_filter($cmdsForV9, fn($c) => str_contains($c, 'DATA UPDATE fingertmp'));

    expect($v9TmplCmds)->toHaveCount(1);
    expect(array_values($v9TmplCmds)[0])->toContain("TMP={$v9Tmpl}");
    expect(array_values($v9TmplCmds)[0])->not->toContain($v10Tmpl);

    // Commands generated for v10 device
    $cmdsForV10 = $syncService->generateUserProvisionCommands($user, false, $v10Device);
    $v10TmplCmds = array_filter($cmdsForV10, fn($c) => str_contains($c, 'DATA UPDATE fingertmp'));

    expect($v10TmplCmds)->toHaveCount(1);
    expect(array_values($v10TmplCmds)[0])->toContain("TMP={$v10Tmpl}");
    expect(array_values($v10TmplCmds)[0])->not->toContain($v9Tmpl);
});

test('generateUserProvisionCommands skips incompatible templates to prevent -1004 error while still provisioning user PIN', function () {
    $v9Device = Devices::create([
        'device_name' => 'Attendance - old device',
        'serial_number' => 'BRMC231660003',
        'ip_address' => '192.168.5.215',
        'is_active' => 1,
        'fp_version' => 'v9',
    ]);

    // User only has a v10 template in database
    $user = Biometrics::create([
        'biometric_id' => 777,
        'name' => 'New Nurse',
        'privilege' => 0,
        'biometric' => null,
    ]);

    $v10Tmpl = 'SIlTUzIx' . str_repeat('V10_', 200);
    $user->addOrUpdateFingerprint(2, strlen($v10Tmpl), 1, $v10Tmpl, 'v10');
    $user->save();

    $syncService = app(BiometricSyncService::class);
    $cmds = $syncService->generateUserProvisionCommands($user, false, $v9Device);

    // DATA USER command MUST exist with dual PIN
    $userCmds = array_filter($cmds, fn($c) => str_starts_with($c, 'DATA USER'));
    expect($userCmds)->toHaveCount(1);
    expect(array_values($userCmds)[0])->toContain("PIN=777\tPIN2=777\tName=New Nurse");

    // DATA UPDATE fingertmp must NOT exist because v10 cannot be pushed to v9
    $tmplCmds = array_filter($cmds, fn($c) => str_contains($c, 'DATA UPDATE fingertmp'));
    expect($tmplCmds)->toHaveCount(0);
});

test('device handshake containing FPVersion=9 updates device fp_version to v9', function () {
    $device = Devices::create([
        'device_name' => 'Auto Handshake Device',
        'serial_number' => 'AUTO_HS_001',
        'ip_address' => '192.168.5.250',
        'is_active' => 1,
        'fp_version' => 'v10',
    ]);

    // Send push request simulating device handshake options
    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=AUTO_HS_001&table=options',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.5.250'],
        "~ZKFPVersion=9\r\n~DeviceName=Old Attendance\r\n"
    );

    $response->assertStatus(200);

    $device->refresh();
    expect($device->fp_version)->toBe('v9');
});
