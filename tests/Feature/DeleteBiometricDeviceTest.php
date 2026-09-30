<?php

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\DeviceCommandService;
use App\Services\DeviceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->boolean('is_hrbliz')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('biometrics')) {
        Schema::create('biometrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->unique();
            $table->unsignedBigInteger('hrbliz_biometric_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('privilege')->default(0);
            $table->longText('biometric')->nullable();
            $table->timestamps();
        });
    }

    Devices::query()->delete();
    Biometrics::query()->delete();
});

test('app:biometric-delete runs in test mode without altering database', function () {
    Devices::create([
        'device_name' => 'Device 163',
        'serial_number' => 'SN_TEST_163',
        'ip_address' => '192.168.5.163',
        'is_active' => 1,
    ]);

    Biometrics::create([
        'biometric_id' => 489,
        'name' => 'Test Employee',
    ]);

    $exitCode = $this->artisan('app:biometric-delete', [
        'pins' => ['489'],
        '--test' => true,
    ])->run();

    expect($exitCode)->toBe(0);
    expect(Biometrics::where('biometric_id', 489)->exists())->toBeTrue();
});

test('app:biometric-delete with --with-db deletes biometric record from database', function () {
    Devices::create([
        'device_name' => 'Device 163',
        'serial_number' => 'SN_TEST_163',
        'ip_address' => '192.168.5.163',
        'is_active' => 1,
    ]);

    Biometrics::create([
        'biometric_id' => 489,
        'name' => 'Test Employee 489',
    ]);

    Biometrics::create([
        'biometric_id' => 496,
        'name' => 'Test Employee 496',
    ]);

    $exitCode = $this->artisan('app:biometric-delete', [
        'pins' => ['489', '496'],
        '--with-db' => true,
        '--ip' => ['192.168.5.163'],
    ])->run();

    expect($exitCode)->toBe(0);
    expect(Biometrics::where('biometric_id', 489)->exists())->toBeFalse();
    expect(Biometrics::where('biometric_id', 496)->exists())->toBeFalse();
});

test('biometrics:delete-user supports multiple comma-separated PINs', function () {
    $dev = Devices::create([
        'device_name' => 'Ward 1',
        'serial_number' => 'SN_WARD_1',
        'ip_address' => '192.168.5.159',
        'is_active' => 1,
    ]);

    Biometrics::create([
        'biometric_id' => 489,
        'name' => 'Employee 489',
    ]);
    Biometrics::create([
        'biometric_id' => 496,
        'name' => 'Employee 496',
    ]);

    $cmdService = app(DeviceCommandService::class);
    $cmdService->clearCommands('SN_WARD_1');

    $exitCode = $this->artisan('biometrics:delete-user', [
        'pin' => '489,496',
        'device_sn' => 'SN_WARD_1',
        '--with-db' => true,
    ])->run();

    expect($exitCode)->toBe(0);
    expect(Biometrics::where('biometric_id', 489)->exists())->toBeFalse();
    expect(Biometrics::where('biometric_id', 496)->exists())->toBeFalse();

    $queued = $cmdService->getPendingCommands('SN_WARD_1');
    $commands = array_column($queued, 'command');
    expect($commands)->toContain('DATA DELETE USER PIN=489');
    expect($commands)->toContain('DATA DELETE USER PIN=496');

    $cmdService->clearCommands('SN_WARD_1');
});

test('command runner executes app:biometric-delete via API', function () {
    $dev = Devices::create([
        'device_name' => 'Terminal Test',
        'serial_number' => 'SN_API_TEST',
        'ip_address' => '192.168.5.185',
        'is_active' => 1,
    ]);

    Biometrics::create([
        'biometric_id' => 632,
        'name' => 'Employee 632',
    ]);

    $response = $this->postJson('/api/command-runner/run', [
        'command' => 'app:biometric-delete',
        'pin' => '632',
        'device_target' => 'all',
        'params' => [
            'test' => true,
        ],
    ]);

    $response->assertStatus(200);
    $data = $response->json();
    expect($data['success'])->toBeTrue();
    expect($data['exit_code'])->toBe(0);
    expect($data['output'])->toContain('Targeting 1 PIN(s)');
});
