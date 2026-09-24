<?php

use App\Models\Biometrics;
use App\Models\Devices;
use App\Models\DeviceLogs;
use App\Repositories\LogsRepository;
use App\Services\BiometricSyncService;
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
            $table->boolean('for_attendance')->default(0);
            $table->boolean('is_hrbliz')->default(0);
            $table->boolean('receiver_by_default')->default(1);
            $table->string('fp_version')->default('v10')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_cleared_at')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasColumn('devices', 'is_hrbliz')) {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('is_hrbliz')->default(false)->after('for_attendance');
        });
    }

    if (!Schema::hasColumn('devices', 'receiver_by_default')) {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('receiver_by_default')->default(true)->after('is_hrbliz');
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
            $table->longText('face')->nullable();
            $table->longText('biophoto')->nullable();
            $table->string('name_with_biometric')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasColumn('biometrics', 'hrbliz_biometric_id')) {
        Schema::table('biometrics', function (Blueprint $table) {
            $table->unsignedBigInteger('hrbliz_biometric_id')->nullable()->after('biometric_id');
        });
    }

    if (!Schema::hasTable('employee_profiles')) {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->nullable();
            $table->unsignedBigInteger('personal_information_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('external_employees')) {
        Schema::create('external_employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('device_logs')) {
        Schema::create('device_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->nullable();
            $table->string('name')->nullable();
            $table->string('dtr_date')->nullable();
            $table->string('date_time')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_Shifting')->default(0);
            $table->string('schedule')->nullable();
            $table->boolean('active')->default(1);
            $table->string('device_name')->nullable();
            $table->timestamps();
        });
    }

    Devices::query()->delete();
    Biometrics::query()->delete();
    DeviceLogs::query()->delete();
});

test('Devices model supports is_hrbliz boolean casting and scopes', function () {
    $hrblizDev = Devices::create([
        'device_name' => 'HRBLIZ Gate 1',
        'device_id' => '101',
        'serial_number' => 'SN-HRBLIZ-01',
        'ip_address' => '192.168.1.101',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 0,
    ]);

    $stdDev = Devices::create([
        'device_name' => 'Main Gate 1',
        'device_id' => '102',
        'serial_number' => 'SN-STD-01',
        'ip_address' => '192.168.1.102',
        'is_active' => true,
        'is_hrbliz' => false,
        'for_attendance' => 0,
    ]);

    expect($hrblizDev->is_hrbliz)->toBeTrue()
        ->and($stdDev->is_hrbliz)->toBeFalse();

    expect(Devices::hrbliz()->count())->toBe(1)
        ->and(Devices::hrbliz()->first()->id)->toBe($hrblizDev->id);

    expect(Devices::standard()->count())->toBe(1)
        ->and(Devices::standard()->first()->id)->toBe($stdDev->id);
});

test('Biometrics model findByDevicePin resolves dual IDs dynamically', function () {
    $emp = Biometrics::create([
        'biometric_id' => 1001,
        'hrbliz_biometric_id' => 5001,
        'name' => 'Juan Dela Cruz',
        'name_with_biometric' => 'Juan Dela Cruz [1001]',
    ]);

    // Standard device lookup by standard biometric_id
    $foundStandard = Biometrics::findByDevicePin(1001, false);
    expect($foundStandard)->not->toBeNull()
        ->and($foundStandard->id)->toBe($emp->id);

    // Standard device looking for hrbliz ID 5001 will NOT match (unless biometric_id happens to be 5001)
    $notFoundOnStandard = Biometrics::findByDevicePin(5001, false);
    expect($notFoundOnStandard)->toBeNull();

    // HRBLIZ device looking for hrbliz_biometric_id 5001 matches!
    $foundHrbliz = Biometrics::findByDevicePin(5001, true);
    expect($foundHrbliz)->not->toBeNull()
        ->and($foundHrbliz->id)->toBe($emp->id);

    // HRBLIZ device looking for standard ID 1001 does NOT match hrbliz_biometric_id
    $notFoundOnHrbliz = Biometrics::findByDevicePin(1001, true);
    expect($notFoundOnHrbliz)->toBeNull();
});

test('Biometrics model getPinForDevice returns correct terminal-specific PIN', function () {
    $emp = Biometrics::create([
        'biometric_id' => 1001,
        'hrbliz_biometric_id' => 5001,
        'name' => 'Maria Clara',
    ]);

    $hrblizDev = new Devices(['is_hrbliz' => true]);
    $stdDev = new Devices(['is_hrbliz' => false]);

    expect($emp->getPinForDevice($hrblizDev))->toBe(5001)
        ->and($emp->getPinForDevice($stdDev))->toBe(1001);
});

test('LogsRepository dynamically translates HRBLIZ PINs to canonical biometric_id', function () {
    $hrblizDev = Devices::create([
        'device_name' => 'HRBLIZ Turnstile',
        'serial_number' => 'SN-HRBLIZ-99',
        'ip_address' => '192.168.1.99',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 0,
    ]);

    $stdDev = Devices::create([
        'device_name' => 'Standard Turnstile',
        'serial_number' => 'SN-STD-99',
        'ip_address' => '192.168.1.98',
        'is_active' => true,
        'is_hrbliz' => false,
        'for_attendance' => 0,
    ]);

    $emp = Biometrics::create([
        'biometric_id' => 2002,
        'hrbliz_biometric_id' => 8888,
        'name' => 'Crisostomo Ibarra',
        'name_with_biometric' => 'Crisostomo Ibarra [2002]',
    ]);

    $repo = app(LogsRepository::class);

    // 1. Employee punches on HRBLIZ device sending their HRBLIZ ID 8888
    $logHrbliz = $repo->createLog([
        'biometric_id' => 8888,
        'ip_address' => '192.168.1.99',
        'dtr_date' => '2026-09-23',
        'dtr_time' => '08:00:00',
        'dtr_type' => 'Check-In',
    ]);

    expect($logHrbliz)->not->toBeNull();
    // Must be saved under canonical biometric_id 2002 so DTR computation sees it!
    expect((int)$logHrbliz->biometric_id)->toBe(2002)
        ->and($logHrbliz->name)->toBe('Crisostomo Ibarra')
        ->and($logHrbliz->device_name)->toBe('HRBLIZ Turnstile');

    // 2. Employee punches on Standard device sending standard ID 2002
    $logStd = $repo->createLog([
        'biometric_id' => 2002,
        'ip_address' => '192.168.1.98',
        'dtr_date' => '2026-09-23',
        'dtr_time' => '17:00:00',
        'dtr_type' => 'Check-Out',
    ]);

    expect($logStd)->not->toBeNull()
        ->and((int)$logStd->biometric_id)->toBe(2002)
        ->and($logStd->name)->toBe('Crisostomo Ibarra')
        ->and($logStd->device_name)->toBe('Standard Turnstile');

    // Both logs exist under the canonical employee ID 2002 for downstream timesheet / DTR generation
    $allLogs = DeviceLogs::where('biometric_id', 2002)->orderBy('date_time', 'asc')->get();
    expect($allLogs->count())->toBe(2);
});

test('DeviceController updates is_hrbliz and filters paginated devices', function () {
    $dev = Devices::create([
        'device_name' => 'Terminal A',
        'device_id' => '1',
        'serial_number' => 'SN-TEST-01',
        'ip_address' => '192.168.1.50',
        'is_active' => true,
        'is_hrbliz' => false,
        'for_attendance' => 0,
    ]);

    // 1. Test updating is_hrbliz via PUT /api/devices/{id}/name
    $response = $this->putJson("/api/devices/{$dev->id}/name", [
        'device_name' => 'Terminal A (HRBLIZ)',
        'is_hrbliz' => true,
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $dev->id,
                'device_name' => 'Terminal A (HRBLIZ)',
                'is_hrbliz' => true,
            ],
        ]);

    expect($dev->fresh()->is_hrbliz)->toBeTrue();

    // 2. Test toggling via PATCH /api/devices/{id}/roles
    $toggleRes = $this->patchJson("/api/devices/{$dev->id}/roles", [
        'is_hrbliz' => false,
    ]);

    $toggleRes->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'is_hrbliz' => false,
            ],
        ]);

    expect($dev->fresh()->is_hrbliz)->toBeFalse();

    // 3. Test legacy endpoint updateDeviceStatusLegacy (api/dtr-device-updatedevicestatus)
    $legacyRes = $this->postJson('/api/dtr-device-updatedevicestatus', [
        'id' => $dev->id,
        'field' => 'is_hrbliz',
        'value' => 1,
    ]);

    $legacyRes->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'is_hrbliz' => true,
            ],
        ]);

    expect($dev->fresh()->is_hrbliz)->toBeTrue();

    // 4. Test filtering in /api/devices/paginated
    $resHrblizFilter = $this->getJson('/api/devices/paginated?hrbliz=1');
    $resHrblizFilter->assertOk();
    expect(count($resHrblizFilter->json('data')))->toBe(1);

    $resStdFilter = $this->getJson('/api/devices/paginated?hrbliz=0');
    $resStdFilter->assertOk();
    expect(count($resStdFilter->json('data')))->toBe(0);
});

test('BiometricSyncService uses device-specific PIN during user provisioning', function () {
    $hrblizDev = Devices::create([
        'device_name' => 'HRBLIZ Terminal',
        'serial_number' => 'SN-HRBLIZ-SYNC',
        'ip_address' => '192.168.1.80',
        'is_active' => true,
        'is_hrbliz' => true,
        'receiver_by_default' => 1,
        'fp_version' => 'v10',
        'for_attendance' => 0,
    ]);

    $stdDev = Devices::create([
        'device_name' => 'Standard Terminal',
        'serial_number' => 'SN-STD-SYNC',
        'ip_address' => '192.168.1.81',
        'is_active' => true,
        'is_hrbliz' => false,
        'fp_version' => 'v10',
        'for_attendance' => 0,
    ]);

    $emp = Biometrics::create([
        'biometric_id' => 3003,
        'hrbliz_biometric_id' => 7777,
        'name' => 'Simoun',
        'privilege' => 0,
    ]);

    $syncService = app(BiometricSyncService::class);

    // HRBLIZ device provisioning command must use PIN 7777
    $hrblizCmds = $syncService->generateUserProvisionCommands($emp, true, $hrblizDev);
    expect($hrblizCmds)->not->toBeEmpty();
    expect($hrblizCmds[0])->toContain('PIN=7777');

    // Standard device provisioning command must use PIN 3003
    $stdCmds = $syncService->generateUserProvisionCommands($emp, true, $stdDev);
    expect($stdCmds)->not->toBeEmpty();
    expect($stdCmds[0])->toContain('PIN=3003');
});

test('punch ingestion accepts status 0 (In), 1 (Out), and 255 (Global) normally', function () {
    $dev = Devices::create([
        'device_name' => 'HRBLIZ Gate',
        'serial_number' => 'SN-HRBLIZ-PUNCH',
        'ip_address' => '192.168.1.123',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 0,
    ]);

    Biometrics::create([
        'biometric_id' => 4004,
        'hrbliz_biometric_id' => 9999,
        'name' => 'Maria Clara',
        'privilege' => 0,
    ]);

    $mockLogsRepo = Mockery::mock(LogsRepository::class, [app(\App\Contracts\DeviceRepositoryInterface::class)])->makePartial();
    $mockLogsRepo->shouldReceive('logExists')->andReturn(false);
    app()->instance(\App\Contracts\LogsRepositoryInterface::class, $mockLogsRepo);

    // Push punch with status 0 (Check-In)
    $payloadIn = "9999\t2026-09-23 08:00:00\t0";
    $responseIn = $this->call('POST', '/iclock/cdata?SN=SN-HRBLIZ-PUNCH', [], [], [], ['REMOTE_ADDR' => '192.168.1.123', 'CONTENT_TYPE' => 'text/plain'], $payloadIn);
    $responseIn->assertStatus(200);

    // Push punch with status 1 (Check-Out)
    $payloadOut = "9999\t2026-09-23 12:00:00\t1";
    $responseOut = $this->call('POST', '/iclock/cdata?SN=SN-HRBLIZ-PUNCH', [], [], [], ['REMOTE_ADDR' => '192.168.1.123', 'CONTENT_TYPE' => 'text/plain'], $payloadOut);
    $responseOut->assertStatus(200);

    // Push punch with status 255 (Global / UMIS)
    $payloadGlobal = "9999\t2026-09-23 17:00:00\t255";
    $responseGlobal = $this->call('POST', '/iclock/cdata?SN=SN-HRBLIZ-PUNCH', [], [], [], ['REMOTE_ADDR' => '192.168.1.123', 'CONTENT_TYPE' => 'text/plain'], $payloadGlobal);
    $responseGlobal->assertStatus(200);

    $logs = DeviceLogs::where('biometric_id', 4004)->orderBy('date_time', 'asc')->get();
    expect($logs)->toHaveCount(3);
    expect($logs[0]->status)->toBe('0');
    expect($logs[1]->status)->toBe('1');
    expect($logs[2]->status)->toBe('255');
});
