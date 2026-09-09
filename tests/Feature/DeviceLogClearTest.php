<?php

use App\Models\Devices;
use App\Services\DeviceCommandService;
use App\Services\DeviceService;
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
            $table->timestamp('last_cleared_at')->nullable();
            $table->timestamps();
        });
    }

    app(DeviceCommandService::class)->clearCommands();
    Devices::query()->delete();
});

test('clearAttendanceLogsFromDevice safely skips offline device and does not queue CLEAR LOG', function () {
    $device = Devices::create([
        'device_name' => 'Offline Device',
        'serial_number' => 'OFFLINE_SN_001',
        'ip_address' => '10.254.254.254', // non-routable offline IP
        'is_active' => 1,
        'last_seen_at' => now()->subHours(5), // Offline (> 2 minutes)
    ]);

    $service = app(DeviceService::class);
    $result = $service->clearAttendanceLogsFromDevice($device, ['method' => 'adms']);

    expect($result['status'])->toBe('skipped_offline')
        ->and($result['is_online'])->toBeFalse()
        ->and($device->fresh()->last_cleared_at)->toBeNull();

    // Verify no command was queued for the offline device
    $commands = app(DeviceCommandService::class)->getPendingCommands('OFFLINE_SN_001');
    expect($commands)->toBeEmpty();
});

test('clearAttendanceLogsFromDevice in dry_run mode performs checks without queueing commands or wiping data', function () {
    $device = Devices::create([
        'device_name' => 'Online Device',
        'serial_number' => 'ONLINE_SN_001',
        'ip_address' => '127.0.0.1',
        'is_active' => 1,
        'last_seen_at' => now(), // Online
    ]);

    $service = app(DeviceService::class);
    $result = $service->clearAttendanceLogsFromDevice($device, [
        'method' => 'adms',
        'dry_run' => true,
        'skip_sync' => true,
        'force' => true,
    ]);

    expect($result['status'])->toBe('dry_run_success')
        ->and($device->fresh()->last_cleared_at)->toBeNull();

    $commands = app(DeviceCommandService::class)->getPendingCommands('ONLINE_SN_001');
    expect($commands)->toBeEmpty();
});

test('clearAttendanceLogsFromDevice queues ADMS CLEAR LOG when forced or online', function () {
    $device = Devices::create([
        'device_name' => 'Test Terminal',
        'serial_number' => 'CLEAR_SN_001',
        'ip_address' => '127.0.0.1',
        'is_active' => 1,
        'last_seen_at' => now(),
    ]);

    $service = app(DeviceService::class);
    $result = $service->clearAttendanceLogsFromDevice($device, [
        'method' => 'adms',
        'force' => true,
        'skip_sync' => true,
    ]);

    expect($result['status'])->toBe('queued')
        ->and($result['method'])->toBe('adms')
        ->and($device->fresh()->last_cleared_at)->not->toBeNull();

    $commands = app(DeviceCommandService::class)->getPendingCommands('CLEAR_SN_001');
    expect($commands)->toHaveCount(1)
        ->and($commands[0]['command'])->toBe('CLEAR LOG');
});

test('needsLogClear and scopeNeedingLogClear correctly identify devices not cleared in 7 days', function () {
    $clearedRecently = Devices::create([
        'device_name' => 'Cleared Recently',
        'serial_number' => 'RECENT_001',
        'ip_address' => '192.168.1.10',
        'is_active' => 1,
        'last_cleared_at' => now()->subDays(2),
    ]);

    $neverCleared = Devices::create([
        'device_name' => 'Never Cleared',
        'serial_number' => 'NEVER_001',
        'ip_address' => '192.168.1.11',
        'is_active' => 1,
        'last_cleared_at' => null,
    ]);

    $clearedLongAgo = Devices::create([
        'device_name' => 'Cleared Long Ago',
        'serial_number' => 'OLD_001',
        'ip_address' => '192.168.1.12',
        'is_active' => 1,
        'last_cleared_at' => now()->subDays(10),
    ]);

    expect($clearedRecently->needsLogClear(7))->toBeFalse()
        ->and($neverCleared->needsLogClear(7))->toBeTrue()
        ->and($clearedLongAgo->needsLogClear(7))->toBeTrue();

    $needingClearIds = Devices::needingLogClear(7)->pluck('id')->all();
    expect($needingClearIds)->toContain($neverCleared->id, $clearedLongAgo->id)
        ->and($needingClearIds)->not->toContain($clearedRecently->id);
});

test('devices:clear-logs command works with dry-run and single device argument', function () {
    $device = Devices::create([
        'device_name' => 'Artisan Test Device',
        'serial_number' => 'ARTISAN_001',
        'ip_address' => '127.0.0.1',
        'is_active' => 1,
        'last_seen_at' => now(),
    ]);

    $this->artisan('devices:clear-logs', [
        'device_id' => $device->id,
        '--dry-run' => true,
        '--force' => true,
        '--skip-sync' => true,
        '--method' => 'adms',
    ])->assertSuccessful();
});

test('API endpoint POST /api/devices/{id}/clear-logs returns json response', function () {
    $device = Devices::create([
        'device_name' => 'API Test Device',
        'serial_number' => 'API_SN_001',
        'ip_address' => '127.0.0.1',
        'is_active' => 1,
        'last_seen_at' => now(),
    ]);

    $response = $this->postJson("/api/devices/{$device->id}/clear-logs", [
        'dry_run' => true,
        'force' => true,
        'skip_sync' => true,
        'method' => 'adms',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'device_id' => $device->id,
                'status' => 'dry_run_success',
            ],
        ]);
});
