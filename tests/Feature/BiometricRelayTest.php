<?php

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
            $table->string('name_with_biometric')->nullable();
            $table->timestamps();
        });
    }

    app(DeviceCommandService::class)->clearCommands();
    Devices::query()->delete();

    // Create test devices
    Devices::create([
        'device_name' => 'Device 1 (Source)',
        'serial_number' => 'TEST_SN_001',
        'ip_address' => '192.168.1.101',
        'is_active' => 1,
        'is_registration' => 1,
        'for_attendance' => 0,
    ]);

    Devices::create([
        'device_name' => 'Device 2 (Target)',
        'serial_number' => 'TEST_SN_002',
        'ip_address' => '192.168.1.102',
        'is_active' => 1,
        'is_registration' => 0,
        'for_attendance' => 1,
    ]);

    Devices::create([
        'device_name' => 'Device 3 (Target)',
        'serial_number' => 'TEST_SN_003',
        'ip_address' => '192.168.1.103',
        'is_active' => 1,
        'is_registration' => 0,
        'for_attendance' => 1,
    ]);
});

afterEach(function () {
    app(DeviceCommandService::class)->clearCommands();
    Devices::query()->delete();
});

test('enrollment on source device queues DATA USER for all other active devices in file', function () {
    $payload = "PIN=5001\tName=Test Employee\tPri=0\tPasswd=\tCard=998877\tGrp=1";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=TEST_SN_001&table=USER',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);
    expect($response->getContent())->toContain('OK');

    $commandService = app(DeviceCommandService::class);
    $commandsDev2 = $commandService->getAllCommands('TEST_SN_002');
    $commandsDev3 = $commandService->getAllCommands('TEST_SN_003');
    $commandsDev1 = $commandService->getAllCommands('TEST_SN_001');

    expect($commandsDev2)->toHaveCount(1);
    expect($commandsDev3)->toHaveCount(1);
    expect($commandsDev1)->toHaveCount(0);

    expect($commandsDev2[0]['command'])->toContain('DATA USER');
    expect($commandsDev2[0]['command'])->toContain('PIN=5001');
    expect($commandsDev2[0]['command'])->toContain('Name=Test Employee');
    expect($commandsDev2[0]['status'])->toBe('PENDING');
});

test('biometric template enrollment queues DATA USER and DATA UPDATE for target devices in file', function () {
    $payload = "PIN=5001\tFingerID=0\tSize=568\tValid=1\tTemplate=sample_template_data_123";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=TEST_SN_001&table=TEMPLATEV10',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    $commandService = app(DeviceCommandService::class);
    $commandsDev2 = $commandService->getAllCommands('TEST_SN_002');

    // Should queue DATA USER first (to ensure user exists) then DATA UPDATE
    expect($commandsDev2)->toHaveCount(2);
    expect($commandsDev2[0]['command'])->toContain('DATA USER PIN=5001');
    expect($commandsDev2[1]['command'])->toContain('DATA UPDATE templatev10');
    expect($commandsDev2[1]['command'])->toContain('PIN=5001');
    expect($commandsDev2[1]['command'])->toContain('Template=sample_template_data_123');
});

test('syncUserAndTemplatesToDevice provisions user profile and all templates to a specific device', function () {
    $syncService = app(\App\Services\BiometricSyncService::class);

    $templates = [
        ['Finger_ID' => '3', 'Size' => '612', 'Valid' => '1', 'Template' => 'TMP_3'],
        ['Finger_ID' => '5', 'Size' => '1288', 'Valid' => '1', 'Template' => 'TMP_5'],
    ];

    \App\Models\Biometrics::create([
        'biometric_id' => 99499,
        'name' => 'Target Provision User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $count = $syncService->syncUserAndTemplatesToDevice('TEST_SN_002', 99499);

    // 1 user command + 2 template commands = 3 total commands
    expect($count)->toBe(3);

    $commandService = app(DeviceCommandService::class);
    $queuedCommands = $commandService->getAllCommands('TEST_SN_002');

    expect($queuedCommands)->toHaveCount(3);
    expect($queuedCommands[0]['command'])->toContain('DATA USER PIN=99499');
    expect($queuedCommands[1]['command'])->toContain('DATA UPDATE fingertmp');
    expect($queuedCommands[1]['command'])->toContain('FID=3');
    expect($queuedCommands[2]['command'])->toContain('DATA UPDATE fingertmp');
    expect($queuedCommands[2]['command'])->toContain('FID=5');
});

test('target device polls getrequest and receives queued commands formatted with C:ID:', function () {
    $commandService = app(DeviceCommandService::class);

    // Queue a command for DEV002
    $cmd = $commandService->queueCommand(
        'TEST_SN_002',
        "DATA USER PIN=5001\tName=Test Employee\tPri=0\tPasswd=\tCard=998877\tGrp=1"
    );

    $response = $this->get('/iclock/getrequest?SN=TEST_SN_002');

    $response->assertStatus(200);
    $content = $response->getContent();

    expect($content)->toContain("C:{$cmd['id']}:DATA USER");

    // Command status should transition to SENT
    $all = $commandService->getAllCommands('TEST_SN_002');
    expect($all[0]['status'])->toBe('SENT');
});

test('target device sends ACK via devicecmd and updates status to SUCCESS', function () {
    $commandService = app(DeviceCommandService::class);

    $cmd = $commandService->queueCommand(
        'TEST_SN_002',
        "DATA USER PIN=5001\tName=Test Employee"
    );
    $cmd2 = $commandService->queueCommand(
        'TEST_SN_002',
        "DATA USER PIN=5002\tName=Pending Employee"
    );
    $commandService->markCommandsAsSent([$cmd['id']]);

    $ackPayload = "ID={$cmd['id']}&Return=0&CMD=DATA USER";

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN=TEST_SN_002',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        $ackPayload
    );

    $response->assertStatus(200);
    $all = $commandService->getAllCommands('TEST_SN_002');
    expect($all)->toHaveCount(2);
    expect($all[0]['status'])->toBe('SUCCESS');
    expect($all[0]['return_code'])->toBe(0);
    expect($all[1]['status'])->toBe('PENDING');

    // When the remaining command is also ACKed, completed file is automatically pruned
    $this->call(
        'POST',
        '/iclock/devicecmd?SN=TEST_SN_002',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        "ID={$cmd2['id']}&Return=0&CMD=DATA USER"
    );
    expect($commandService->getAllCommands('TEST_SN_002'))->toBeEmpty();
});

test('device deletion OPLOG 2 triggers self-healing auto-restore of user and templates from DB', function () {
    // 1. Create a user in DB with 2 templates
    $templates = [
        ['Finger_ID' => '3', 'Size' => '612', 'Valid' => '1', 'Template' => 'TMP_3_RESTORE'],
        ['Finger_ID' => '9', 'Size' => '1008', 'Valid' => '1', 'Template' => 'TMP_9_RESTORE'],
    ];

    \App\Models\Biometrics::create([
        'biometric_id' => 99777,
        'name' => 'Auto Restore Employee',
        'privilege' => 1,
        'biometric' => json_encode($templates),
    ]);

    // 2. Device 2 (IP: 192.168.1.102, SN: TEST_SN_002) sends OPLOG 2 (Delete User 99777)
    $oplogPayload = "OPLOG 2\t99777\t2026-08-24 10:00:00\t0";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=TEST_SN_002',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.1.102', 'CONTENT_TYPE' => 'text/plain'],
        $oplogPayload
    );

    $response->assertStatus(200);

    // 3. Verify that commands were automatically queued for TEST_SN_002 to restore user & templates
    $commandService = app(DeviceCommandService::class);
    $restorationCommands = $commandService->getAllCommands('TEST_SN_002');

    expect($restorationCommands)->toHaveCount(3);
    expect($restorationCommands[0]['command'])->toContain('DATA USER PIN=99777');
    expect($restorationCommands[0]['command'])->toContain('Name=Auto Restore Employee');
    expect($restorationCommands[1]['command'])->toContain('DATA UPDATE fingertmp');
    expect($restorationCommands[1]['command'])->toContain('FID=3');
    expect($restorationCommands[2]['command'])->toContain('DATA UPDATE fingertmp');
    expect($restorationCommands[2]['command'])->toContain('FID=9');
});

test('admin deleting a target user via OPLOG 9 automatically restores target user from DB', function () {
    // 1. Create target user in DB with 1 template
    $templates = [
        ['Finger_ID' => '6', 'Size' => '968', 'Valid' => '1', 'Template' => 'TMP_6_TARGET'],
    ];

    \App\Models\Biometrics::create([
        'biometric_id' => 99496,
        'name' => 'Dolar, Kim Horace',
        'privilege' => 1,
        'biometric' => json_encode($templates),
    ]);

    // 2. Admin 99493 deletes target user 99496 on Device 2 (IP: 192.168.1.102, SN: TEST_SN_002)
    // OPLOG 9 format: OPLOG 9 \t <admin_pin> \t <timestamp> \t <target_user_pin> \t 0 \t 0 \t 0
    $oplogPayload = "OPLOG 9\t99493\t2026-08-24 10:33:44\t99496\t0\t0\t0";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=TEST_SN_002',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.1.102', 'CONTENT_TYPE' => 'text/plain'],
        $oplogPayload
    );

    $response->assertStatus(200);

    // 3. Verify that commands were automatically queued for TEST_SN_002 to restore target user 99496
    $commandService = app(DeviceCommandService::class);
    $commands = $commandService->getAllCommands('TEST_SN_002');
    $targetCommands = array_values(array_filter($commands, fn($c) => str_contains($c['command'], '99496')));

    expect($targetCommands)->toHaveCount(2);
    expect($targetCommands[0]['command'])->toContain('DATA USER PIN=99496');
    expect($targetCommands[0]['command'])->toContain('Name=Dolar, Kim Horace');
    expect($targetCommands[1]['command'])->toContain('DATA UPDATE fingertmp');
    expect($targetCommands[1]['command'])->toContain('FID=6');
});
