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
