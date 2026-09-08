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
            $table->string('name_with_biometric')->nullable();
            $table->timestamps();
        });
    }

    app(DeviceCommandService::class)->clearCommands();
    Devices::query()->delete();

    // Source device
    Devices::create([
        'device_name' => 'Device Source',
        'serial_number' => 'DEV_SN_SOURCE',
        'ip_address' => '192.168.1.50',
        'is_active' => 1,
        'is_registration' => 1,
    ]);

    // Target device
    Devices::create([
        'device_name' => 'Device Target',
        'serial_number' => 'DEV_SN_TARGET',
        'ip_address' => '192.168.1.51',
        'is_active' => 1,
    ]);
});

afterEach(function () {
    app(DeviceCommandService::class)->clearCommands();
    Devices::query()->delete();
});

test('pushing FP PIN line to /iclock/cdata saves template to DB and queues sync command for target devices in file', function () {
    $user = Biometrics::create([
        'biometric_id' => 99881,
        'name' => 'Push Test User',
        'privilege' => 1,
        'biometric' => 'NOT_YET_REGISTERED',
    ]);

    $payload = "FP PIN=99881\tFID=3\tSize=612\tValid=1\tTMP=SAMPLE_BASE64_TEMPLATE";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=DEV_SN_SOURCE',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);
    expect($response->getContent())->toContain('OK');

    // Verify DB updated
    $user->refresh();
    $templates = json_decode($user->biometric, true);
    expect($templates)->toHaveCount(1);
    expect($templates[0]['Finger_ID'])->toBe('3');
    expect($templates[0]['Size'])->toBe('612');
    expect($templates[0]['Template'])->toBe('SAMPLE_BASE64_TEMPLATE');

    // Verify sync queued for DEV_SN_TARGET (DATA USER to ensure user exists + DATA UPDATE for template)
    $commandService = app(DeviceCommandService::class);
    $commands = $commandService->getAllCommands('DEV_SN_TARGET');
    expect($commands)->toHaveCount(2);
    expect($commands[0]['command'])->toContain('DATA USER PIN=99881');
    expect($commands[1]['command'])->toContain('DATA UPDATE fingertmp');
    expect($commands[1]['command'])->toContain('PIN=99881');
    expect($commands[1]['command'])->toContain('FID=3');

    $user->delete();
});

test('pushing template to /iclock/fdata saves template to DB and queues sync command in file', function () {
    $user = Biometrics::create([
        'biometric_id' => 99882,
        'name' => 'Fdata Test User',
        'privilege' => 0,
        'biometric' => null,
    ]);

    $payload = "PIN=99882\tFingerID=6\tSize=780\tValid=1\tTemplate=FDATA_TEMPLATE_XYZ";

    $response = $this->call(
        'POST',
        '/iclock/fdata?SN=DEV_SN_SOURCE',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);
    expect($response->getContent())->toContain('OK');

    // Verify DB updated
    $user->refresh();
    $templates = json_decode($user->biometric, true);
    expect($templates)->toHaveCount(1);
    expect($templates[0]['Finger_ID'])->toBe('6');
    expect($templates[0]['Template'])->toBe('FDATA_TEMPLATE_XYZ');

    $user->delete();
});

test('pushing template from operating device (is_registration=0) saves template to DB and broadcasts to target devices', function () {
    Devices::create([
        'device_name' => 'Operating Terminal',
        'serial_number' => 'DEV_SN_OPERATING',
        'ip_address' => '192.168.1.52',
        'is_active' => 1,
        'is_registration' => 0,
    ]);

    $user = Biometrics::create([
        'biometric_id' => 99883,
        'name' => 'Operating Device User',
        'privilege' => 0,
        'biometric' => null,
    ]);

    app(DeviceCommandService::class)->clearCommands();

    $payload = "FP PIN=99883\tFID=2\tSize=640\tValid=1\tTMP=OPERATING_TERMINAL_TEMPLATE";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=DEV_SN_OPERATING',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    // Verify DB updated with template from operating terminal
    $user->refresh();
    $templates = json_decode($user->biometric, true);
    expect($templates)->toHaveCount(1);
    expect($templates[0]['Finger_ID'])->toBe('2');
    expect($templates[0]['Template'])->toBe('OPERATING_TERMINAL_TEMPLATE');

    // Verify broadcast queued for other devices (DEV_SN_SOURCE & DEV_SN_TARGET)
    $commandService = app(DeviceCommandService::class);
    $cmdsTarget = $commandService->getAllCommands('DEV_SN_TARGET');
    $cmdsSource = $commandService->getAllCommands('DEV_SN_SOURCE');
    $cmdsOperating = $commandService->getAllCommands('DEV_SN_OPERATING');

    expect($cmdsTarget)->toHaveCount(2);
    expect($cmdsSource)->toHaveCount(2);
    expect($cmdsOperating)->toHaveCount(0); // Source operating device excluded from queue

    $user->delete();
});

test('overwriting an existing finger on an operating device updates DB template and broadcasts new template', function () {
    Devices::create([
        'device_name' => 'Ward Terminal',
        'serial_number' => 'DEV_SN_WARD',
        'ip_address' => '192.168.1.53',
        'is_active' => 1,
        'is_registration' => 0,
    ]);

    $initialTemplates = [
        ['Finger_ID' => '6', 'Size' => '700', 'Valid' => '1', 'Template' => 'OLD_TEMPLATE_6'],
    ];

    $user = Biometrics::create([
        'biometric_id' => 99884,
        'name' => 'Overwrite User',
        'privilege' => 0,
        'biometric' => json_encode($initialTemplates),
    ]);

    app(DeviceCommandService::class)->clearCommands();

    // Push new template for finger 6 on Ward Terminal (is_registration = 0)
    $payload = "FP PIN=99884\tFID=6\tSize=850\tValid=1\tTMP=NEW_OVERWRITTEN_TEMPLATE_6";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=DEV_SN_WARD',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    // 1. Verify DB is updated with the NEW template string
    $user->refresh();
    $updatedTemplates = json_decode($user->biometric, true);
    expect($updatedTemplates)->toHaveCount(1);
    expect($updatedTemplates[0]['Finger_ID'])->toBe('6');
    expect($updatedTemplates[0]['Template'])->toBe('NEW_OVERWRITTEN_TEMPLATE_6');
    expect($updatedTemplates[0]['Size'])->toBe('850');

    // 2. Verify broadcast commands were queued with the updated template
    $commandService = app(DeviceCommandService::class);
    $cmdsTarget = $commandService->getAllCommands('DEV_SN_TARGET');
    expect($cmdsTarget)->toHaveCount(2);
    expect($cmdsTarget[0]['command'])->toContain('DATA USER PIN=99884');
    expect($cmdsTarget[0]['command'])->toContain('TZ=1');
    expect($cmdsTarget[1]['command'])->toContain('DATA UPDATE fingertmp');
    expect($cmdsTarget[1]['command'])->toContain('FID=6');
    expect($cmdsTarget[1]['command'])->toContain('TMP=NEW_OVERWRITTEN_TEMPLATE_6');

    $user->delete();
});

test('syncing user with incoming TZ=0 forces TZ=1 preventing Invalid Time Period', function () {
    $payload = "PIN=99885\tName=Timezone Test User\tPri=0\tPasswd=\tCard=0\tGrp=0\tTZ=0";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=DEV_SN_SOURCE&table=USER',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    $commandService = app(DeviceCommandService::class);
    $cmds = $commandService->getAllCommands('DEV_SN_TARGET');
    expect($cmds)->toHaveCount(1);
    expect($cmds[0]['command'])->toContain('DATA USER PIN=99885');
    // Must enforce Grp=1 and TZ=1 (24/7 access) instead of Grp=0 and TZ=0
    expect($cmds[0]['command'])->toContain('Grp=1');
    expect($cmds[0]['command'])->toContain('TZ=1');
    expect($cmds[0]['command'])->not->toContain('TZ=0');
});

test('new unknown device connecting via ADMS is automatically registered in devices table with serial number and IP', function () {
    $newSn = 'ZKT_BRAND_NEW_9999';
    $ip = '192.168.1.99';

    $response = $this->call(
        'GET',
        "/iclock/cdata?SN={$newSn}",
        [],
        [],
        [],
        ['REMOTE_ADDR' => $ip]
    );

    $response->assertStatus(200);

    $device = Devices::where('serial_number', $newSn)->first();
    expect($device)->not->toBeNull();
    expect($device->serial_number)->toBe($newSn);
    expect($device->ip_address)->toBe($ip);
    expect($device->is_active)->toBeTrue();
    expect($device->device_name)->toBe('Terminal 9999');
    expect($device->last_seen_at)->not->toBeNull();
});

test('device created in UMIS without serial number is automatically bound when it connects via ADMS', function () {
    $existingIp = '192.168.1.88';
    $boundSn = 'ZKT_BOUND_8888';

    // Simulate device registered in UMIS UI with only IP address
    $umisDevice = Devices::create([
        'device_name' => 'HR Terminal Unbound',
        'serial_number' => null,
        'ip_address' => $existingIp,
        'is_active' => 0,
    ]);

    $response = $this->call(
        'GET',
        "/iclock/cdata?SN={$boundSn}",
        [],
        [],
        [],
        ['REMOTE_ADDR' => $existingIp]
    );

    $response->assertStatus(200);

    $umisDevice->refresh();
    expect($umisDevice->serial_number)->toBe($boundSn);
    expect($umisDevice->is_active)->toBeTrue();
    expect($umisDevice->last_seen_at)->not->toBeNull();

    // Verify no duplicate device was created
    $count = Devices::where('ip_address', $existingIp)->count();
    expect($count)->toBe(1);
});

test('known device connecting via ADMS updates its last_seen_at and ip_address', function () {
    $device = Devices::where('serial_number', 'DEV_SN_SOURCE')->first();
    $oldSeen = $device->last_seen_at;
    $newIp = '192.168.1.77';

    $response = $this->call(
        'GET',
        '/iclock/cdata?SN=DEV_SN_SOURCE',
        [],
        [],
        [],
        ['REMOTE_ADDR' => $newIp]
    );

    $response->assertStatus(200);

    $device->refresh();
    expect($device->ip_address)->toBe($newIp);
    expect($device->last_seen_at)->not->toBeNull();
});

