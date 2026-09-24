<?php

use App\Models\Biometrics;
use App\Models\Devices;
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
});

test('command runner manifest returns allowed commands and devices', function () {
    Devices::create([
        'device_name' => 'Test Terminal 1',
        'serial_number' => 'SN123456',
        'ip_address' => '192.168.1.100',
        'is_active' => 1,
    ]);

    $response = $this->getJson('/api/command-runner/manifest');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'commands',
            'devices',
            'finger_names',
        ]);

    $data = $response->json();
    expect($data['success'])->toBeTrue();
    expect($data['commands'])->toHaveKey('biometrics:sync-device');
    expect($data['commands'])->toHaveKey('biometrics:check-device');
    expect($data['commands'])->toHaveKey('devices:pull-logs');
    expect($data['commands'])->toHaveKey('devices:clear-logs');
    expect(count($data['devices']))->toBeGreaterThanOrEqual(1);
});

test('command runner searches employees by PIN or name', function () {
    Biometrics::create([
        'biometric_id' => 1437,
        'name' => 'Juan Dela Cruz',
    ]);

    $response = $this->getJson('/api/command-runner/employees?q=1437');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'employees' => [
                '*' => ['biometric_id', 'name']
            ]
        ]);

    $data = $response->json();
    expect($data['employees'][0]['biometric_id'])->toBe('1437');
    expect($data['employees'][0]['name'])->toBe('Juan Dela Cruz');
});

test('command runner rejects unauthorized commands with 422', function () {
    $response = $this->postJson('/api/command-runner/run', [
        'command' => 'migrate:fresh',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});

test('command runner requires PIN when command mandates it', function () {
    $response = $this->postJson('/api/command-runner/run', [
        'command' => 'biometrics:check-device',
        'device_target' => 'all',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});

test('command runner successfully runs a whitelisted command and captures output', function () {
    $response = $this->postJson('/api/command-runner/run', [
        'command' => 'biometrics:command-status',
        'params' => [
            'limit' => 5,
        ],
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'exit_code',
            'command',
            'output',
            'duration',
            'duration_ms',
            'executed_at',
        ]);

    $data = $response->json();
    expect($data['success'])->toBeTrue();
    expect($data['exit_code'])->toBe(0);
    expect($data['output'])->not->toBeEmpty();
});

test('biometrics:delete-user enforces strict deletion using exact written PIN without translation', function () {
    $dev = Devices::create([
        'device_name' => 'Registration Device 161',
        'serial_number' => 'UCR_TEST_DELETE',
        'ip_address' => '192.168.5.161',
        'is_active' => 1,
    ]);

    // Create a biometric record with a different ID (493) to ensure it is NOT substituted
    Biometrics::create([
        'biometric_id' => 493,
        'name' => 'Existing User',
    ]);

    $cmdService = app(\App\Services\DeviceCommandService::class);
    $cmdService->clearCommands('UCR_TEST_DELETE');

    // Run strict deletion for PIN 5180
    $exitCode = $this->artisan('biometrics:delete-user', [
        'pin' => '5180',
        'device_sn' => 'UCR_TEST_DELETE',
    ])->run();

    expect($exitCode)->toBe(0);

    $queued = $cmdService->getPendingCommands('UCR_TEST_DELETE');
    expect($queued)->toHaveCount(1);
    expect($queued[0]['command'])->toBe('DATA DELETE USER PIN=5180');
    expect($queued[0]['command'])->not->toContain('493');

    $cmdService->clearCommands('UCR_TEST_DELETE');
});
