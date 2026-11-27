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
            $table->boolean('is_hrbliz')->default(0);
            $table->boolean('receiver_by_default')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_cleared_at')->nullable();
            $table->timestamps();
        });
    } else {
        Schema::table('devices', function (Blueprint $table) {
            if (!Schema::hasColumn('devices', 'is_hrbliz')) {
                $table->boolean('is_hrbliz')->default(0);
            }
            if (!Schema::hasColumn('devices', 'receiver_by_default')) {
                $table->boolean('receiver_by_default')->nullable();
            }
            if (!Schema::hasColumn('devices', 'last_cleared_at')) {
                $table->timestamp('last_cleared_at')->nullable();
            }
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

test('command runner manifest returns all receive sync devices without pagination limit', function () {
    for ($i = 1; $i <= 15; $i++) {
        Devices::create([
            'device_name' => "Receive Sync Terminal {$i}",
            'serial_number' => "SN_SYNC_{$i}",
            'ip_address' => "192.168.10.{$i}",
            'is_active' => 1,
            'is_hrbliz' => $i > 12 ? 1 : 0,
            'receiver_by_default' => 1,
        ]);
    }

    Devices::create([
        'device_name' => 'HRBLIZ Send-Only Terminal',
        'serial_number' => 'SN_SEND_ONLY',
        'ip_address' => '192.168.10.200',
        'is_active' => 1,
        'is_hrbliz' => 1,
        'receiver_by_default' => 0,
    ]);

    $response = $this->getJson('/api/command-runner/manifest');
    $response->assertStatus(200);

    $devices = collect($response->json('devices'));
    $receiveSync = $devices->filter(fn($d) => $d['receiver_by_default'] === true);
    $sendOnly = $devices->filter(fn($d) => $d['receiver_by_default'] === false);

    expect($receiveSync->count())->toBeGreaterThanOrEqual(15);
    expect($sendOnly->pluck('serial_number'))->toContain('SN_SEND_ONLY');
});

test('command runner supports multiselect device_targets and enforces HRBLIZ send-only guard', function () {
    $dev1 = Devices::create([
        'device_name' => 'Multi Target 1',
        'serial_number' => 'SN_MULTI_1',
        'ip_address' => '192.168.20.1',
        'is_active' => 1,
        'receiver_by_default' => 1,
    ]);
    $dev2 = Devices::create([
        'device_name' => 'Multi Target 2',
        'serial_number' => 'SN_MULTI_2',
        'ip_address' => '192.168.20.2',
        'is_active' => 1,
        'receiver_by_default' => 1,
    ]);
    $sendOnlyHrbliz = Devices::create([
        'device_name' => 'HRBLIZ ReadOnly',
        'serial_number' => 'SN_HRB_RO',
        'ip_address' => '192.168.20.3',
        'is_active' => 1,
        'is_hrbliz' => 1,
        'receiver_by_default' => 0,
    ]);

    // Multi-device execution succeeds and combines output for both devices
    $res = $this->postJson('/api/command-runner/run', [
        'command' => 'biometrics:command-status',
        'device_target' => 'multiple',
        'device_targets' => [$dev1->id, $dev2->id],
        'params' => ['limit' => 5],
    ]);

    $res->assertStatus(200)
        ->assertJson([
            'success' => true,
            'exit_code' => 0,
            'devices_executed' => 2,
        ]);
    expect($res->json('output'))->toContain('Multi Target 1');
    expect($res->json('output'))->toContain('Multi Target 2');

    // Multi-device write command blocks if any selected device is HRBLIZ send-only
    $blocked = $this->postJson('/api/command-runner/run', [
        'command' => 'biometrics:sync-device',
        'device_target' => 'multiple',
        'device_targets' => [$dev1->id, $sendOnlyHrbliz->id],
    ]);

    $blocked->assertStatus(422)
        ->assertJson(['success' => false]);
});

test('paginated devices endpoint filters receive sync vs send-only devices', function () {
    Devices::create([
        'device_name' => 'Std Sync Dev',
        'serial_number' => 'SN_STD_SYNC',
        'ip_address' => '192.168.30.1',
        'is_active' => 1,
        'is_hrbliz' => 0,
        'receiver_by_default' => 1,
    ]);
    Devices::create([
        'device_name' => 'HRBLIZ Sync Dev',
        'serial_number' => 'SN_HRB_SYNC',
        'ip_address' => '192.168.30.2',
        'is_active' => 1,
        'is_hrbliz' => 1,
        'receiver_by_default' => 1,
    ]);
    Devices::create([
        'device_name' => 'HRBLIZ SendOnly Dev',
        'serial_number' => 'SN_HRB_SO',
        'ip_address' => '192.168.30.3',
        'is_active' => 1,
        'is_hrbliz' => 1,
        'receiver_by_default' => 0,
    ]);

    $syncRes = $this->getJson('/api/devices/paginated?receiver=1&per_page=all');
    $syncRes->assertStatus(200);
    $syncNames = collect($syncRes->json('data'))->pluck('device_name');
    expect($syncNames)->toContain('Std Sync Dev');
    expect($syncNames)->toContain('HRBLIZ Sync Dev');
    expect($syncNames)->not->toContain('HRBLIZ SendOnly Dev');

    $soRes = $this->getJson('/api/devices/paginated?receiver=0&per_page=all');
    $soRes->assertStatus(200);
    $soNames = collect($soRes->json('data'))->pluck('device_name');
    expect($soNames)->toContain('HRBLIZ SendOnly Dev');
    expect($soNames)->not->toContain('Std Sync Dev');
});
