<?php

use App\Models\Biometrics;
use App\Models\Devices;
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

    Devices::query()->delete();
    Biometrics::query()->delete();

    Devices::create([
        'device_name' => 'Test Terminal 1',
        'serial_number' => 'TEST_SN_001',
        'ip_address' => '192.168.254.254',
        'is_active' => 1,
    ]);
});

test('biometrics:check-device errors out if no target device or --all-devices flag is given', function () {
    $this->artisan('biometrics:check-device', ['pin' => 493])
        ->expectsOutputToContain('Please specify a target device serial number or use --all-devices.')
        ->assertExitCode(1);
});

test('biometrics:check-device errors out if specified device serial number is not in DB', function () {
    $this->artisan('biometrics:check-device', ['pin' => 493, 'device_sn' => 'NON_EXISTENT_SN'])
        ->expectsOutputToContain('not found in database')
        ->assertExitCode(1);
});

test('biometrics:check-device displays database authority and device query table for single device', function () {
    Biometrics::create([
        'biometric_id' => 493,
        'name' => 'Reenjay Test',
        'privilege' => 14,
        'biometric' => json_encode([
            ['Finger_ID' => '6', 'Size' => '566', 'Valid' => '1', 'Template' => 'TEST_TMP']
        ]),
    ]);

    $this->artisan('biometrics:check-device', ['pin' => 493, 'device_sn' => 'TEST_SN_001'])
        ->expectsOutputToContain('BIOMETRIC DEVICE LIVE FINGERPRINT INSPECTION — PIN 493')
        ->expectsOutputToContain('Reenjay Test')
        ->expectsOutputToContain('Slot 6 (Left Index')
        ->expectsOutputToContain('TEST_SN_001')
        ->assertExitCode(0);
});

test('biometrics:check-device runs successfully with --all-devices flag', function () {
    Biometrics::create([
        'biometric_id' => 493,
        'name' => 'All Devices User',
        'privilege' => 0,
        'biometric' => 'NOT_YET_REGISTERED',
    ]);

    $this->artisan('biometrics:check-device', ['pin' => 493, '--all-devices' => true])
        ->expectsOutputToContain('BIOMETRIC DEVICE LIVE FINGERPRINT INSPECTION — PIN 493')
        ->expectsOutputToContain('All Devices User')
        ->assertExitCode(0);
});

test('biometrics:check-device with --clean flag executes safely when no ghosts are detected', function () {
    $this->artisan('biometrics:check-device', ['pin' => 493, 'device_sn' => 'TEST_SN_001', '--clean' => true, '--force' => true])
        ->assertExitCode(0);
});

