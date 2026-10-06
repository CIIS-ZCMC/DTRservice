<?php

use App\Models\Biometrics;
use App\Models\Devices;
use App\Models\DeviceLogs;
use App\Models\DeviceLogsHrbliz;
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

    if (!Schema::hasTable('device_logs_hrbliz')) {
        Schema::create('device_logs_hrbliz', function (Blueprint $table) {
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
    DeviceLogsHrbliz::query()->delete();
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
    // Must be saved in DeviceLogsHrbliz under canonical biometric_id 2002
    expect((int)$logHrbliz->biometric_id)->toBe(2002)
        ->and($logHrbliz->name)->toBe('Crisostomo Ibarra')
        ->and($logHrbliz->device_name)->toBe('HRBLIZ Turnstile')
        ->and($logHrbliz instanceof DeviceLogsHrbliz)->toBeTrue();

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
        ->and($logStd->device_name)->toBe('Standard Turnstile')
        ->and($logStd instanceof DeviceLogs)->toBeTrue()
        ->and($logStd instanceof DeviceLogsHrbliz)->toBeFalse();

    // Standard log is in DeviceLogs, HRBLIZ log is in DeviceLogsHrbliz
    expect(DeviceLogs::where('biometric_id', 2002)->count())->toBe(1);
    expect(DeviceLogsHrbliz::where('biometric_id', 2002)->count())->toBe(1);

    // Both logs are returned by TimeRecordRepository for DTR calculation
    $timeRepo = app(\App\Contracts\TimeRecordRepositoryInterface::class);
    $allTimeLogs = $timeRepo->getDeviceLogsByBiometricId(2002, '2026-09-23', '2026-09-23');
    expect($allTimeLogs)->toHaveCount(2);

    // Both logs are returned for dtr-self and DTR report
    $dtrRepo = app(\App\Contracts\DtrReportRepositoryInterface::class);
    $selfDtr = $dtrRepo->getSelfDtr(2002, '2026-09-23');
    expect($selfDtr['deviceLogs']['logs'])->toHaveCount(2);
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

test('BiometricSyncService does not generate provision commands for HRBLIZ device to prevent updating device data', function () {
    $hrblizDev = Devices::create([
        'device_name' => 'HRBLIZ Terminal',
        'serial_number' => 'SN-HRBLIZ-SYNC',
        'ip_address' => '192.168.1.80',
        'is_active' => true,
        'is_hrbliz' => true,
        'receiver_by_default' => 0,
        'fp_version' => 'v10',
        'for_attendance' => 1,
    ]);

    $stdDev = Devices::create([
        'device_name' => 'Standard Terminal',
        'serial_number' => 'SN-STD-SYNC',
        'ip_address' => '192.168.1.81',
        'is_active' => true,
        'is_hrbliz' => false,
        'fp_version' => 'v10',
        'for_attendance' => 1,
    ]);

    $emp = Biometrics::create([
        'biometric_id' => 3003,
        'hrbliz_biometric_id' => 7777,
        'name' => 'Simoun',
        'privilege' => 0,
    ]);

    $syncService = app(BiometricSyncService::class);

    // HRBLIZ device must NEVER receive user provisioning commands (read-only attendance sender)
    $hrblizCmds = $syncService->generateUserProvisionCommands($emp, true, $hrblizDev);
    expect($hrblizCmds)->toBeEmpty();

    // Standard device provisioning command must use PIN 3003
    $stdCmds = $syncService->generateUserProvisionCommands($emp, true, $stdDev);
    expect($stdCmds)->not->toBeEmpty();
    expect($stdCmds[0])->toContain('PIN=3003');
});

test('connected HRBLIZ device never receives commands via /iclock/getrequest and only sends attendance/dtr', function () {
    $mockLogsRepo = Mockery::mock(LogsRepository::class, [app(\App\Contracts\DeviceRepositoryInterface::class)])->makePartial();
    $mockLogsRepo->shouldReceive('logExists')->andReturn(false);
    app()->instance(\App\Contracts\LogsRepositoryInterface::class, $mockLogsRepo);

    $hrblizDev = Devices::create([
        'device_name' => 'HRBLIZ Terminal Polling',
        'serial_number' => 'SN-HRBLIZ-POLL-01',
        'ip_address' => '192.168.1.200',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 0,
    ]);

    // Attempt to queue a command for the HRBLIZ device
    $cmdService = app(DeviceCommandService::class);
    $cmdService->queueCommand('SN-HRBLIZ-POLL-01', 'DATA USER PIN=7777\tName=Test');

    // 1. Device connects via /iclock/getrequest -> must receive OK with NO commands
    $resGet = $this->call('GET', '/iclock/getrequest?SN=SN-HRBLIZ-POLL-01');
    $resGet->assertStatus(200);
    expect(trim($resGet->getContent()))->toBe('OK');

    // 2. Device connects via /iclock/cdata sending USER registration -> ignored, returns OK
    $resUser = $this->call('POST', '/iclock/cdata?SN=SN-HRBLIZ-POLL-01&table=USER', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "PIN=7777\tName=Test");
    $resUser->assertStatus(200);
    expect(trim($resUser->getContent()))->toBe('OK');

    // 3. Device connects via /iclock/cdata sending ATTLOG (attendance) -> accepted and processed!
    Biometrics::create([
        'biometric_id' => 5005,
        'hrbliz_biometric_id' => 8765,
        'name' => 'Basilio',
        'privilege' => 0,
    ]);

    $punchPayload = "8765\t2026-09-30 08:30:00\t0";
    $resPunch = $this->call('POST', '/iclock/cdata?SN=SN-HRBLIZ-POLL-01', [], [], [], [
        'REMOTE_ADDR' => '192.168.1.200',
        'CONTENT_TYPE' => 'text/plain',
    ], $punchPayload);

    $resPunch->assertStatus(200);
    $savedLog = DeviceLogsHrbliz::where('biometric_id', 5005)->first();
    expect($savedLog)->not->toBeNull()
        ->and($savedLog->status)->toBe('0');
    expect(DeviceLogs::where('biometric_id', 5005)->first())->toBeNull();
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

    $logs = DeviceLogsHrbliz::where('biometric_id', 4004)->orderBy('date_time', 'asc')->get();
    expect($logs)->toHaveCount(3);
    expect($logs[0]->status)->toBe('0');
    expect($logs[1]->status)->toBe('1');
    expect($logs[2]->status)->toBe('255');
    expect(DeviceLogs::where('biometric_id', 4004)->count())->toBe(0);

    // Also assert that DeviceLogAlertController dateEntries and scanDatabase display them!
    $alertController = app(\App\Http\Controllers\DeviceLogAlertController::class);
    $dateResponse = $alertController->dateEntries(request(), '2026-09-23');
    $dateData = $dateResponse->getData(true);
    expect($dateData['total'])->toBe(3);
    expect($dateData['entries'][0]['is_hrbliz'])->toBeTrue();

    $scanResponse = $alertController->scanDatabase(request());
    $scanData = $scanResponse->getData(true);
    expect($scanData['dates']['2026-09-23']['count'])->toBe(3);
});

test('DeviceService refuses modifying operations (syncDeviceTime, restartDevice, turnOffDevice, deleteUsersFromDevice) on HRBLIZ device', function () {
    $dev = Devices::create([
        'device_name' => 'HRBLIZ Safe Gate',
        'serial_number' => 'SN-HRBLIZ-SAFE',
        'ip_address' => '192.168.1.99',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 1,
    ]);

    $devService = app(\App\Services\DeviceService::class);
    $cmdService = app(\App\Services\DeviceCommandService::class);

    // 1. syncDeviceTime -> skipped, 0 queued
    $timeRes = $devService->syncDeviceTime($dev->id);
    expect($timeRes['success'])->toBeFalse();
    expect($timeRes['channel'])->toBe('Skipped');
    expect($cmdService->getAllCommands('SN-HRBLIZ-SAFE'))->toBeEmpty();

    // 2. restartDevice -> skipped, 0 queued
    $restartRes = $devService->restartDevice($dev->id);
    expect($restartRes['success'])->toBeFalse();
    expect($restartRes['channel'])->toBe('Skipped');
    expect($cmdService->getAllCommands('SN-HRBLIZ-SAFE'))->toBeEmpty();

    // 3. turnOffDevice -> throws exception
    expect(fn() => $devService->turnOffDevice($dev->id))->toThrow(Exception::class);

    // 4. deleteUsersFromDevice -> blocked
    $delRes = $devService->deleteUsersFromDevice($dev, ['1234']);
    expect($delRes['success'])->toBeFalse();
    expect($delRes['message'])->toContain('Cannot modify or delete users on HRBLIZ device');
    expect($cmdService->getAllCommands('SN-HRBLIZ-SAFE'))->toBeEmpty();

    // 5. requestLogResend -> skipped_hrbliz, 0 queued
    $resendRes = $devService->requestLogResend($dev);
    expect($resendRes['status'])->toBe('skipped_hrbliz');
    expect($cmdService->getAllCommands('SN-HRBLIZ-SAFE'))->toBeEmpty();
});

test('CommandRunnerController blocks modifying commands when targeting HRBLIZ device', function () {
    $dev = Devices::create([
        'device_name' => 'HRBLIZ Command Blocked',
        'serial_number' => 'SN-HRBLIZ-CMD-BLOCK',
        'ip_address' => '192.168.1.98',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 1,
    ]);

    $modifyingCommands = [
        'biometrics:sync-device',
        'biometrics:check-device',
        'biometrics:check-device-match',
        'biometrics:delete-user',
        'app:biometric-delete',
    ];

    foreach ($modifyingCommands as $cmd) {
        $res = $this->postJson('/command-runner/run', [
            'command' => $cmd,
            'device_target' => (string)$dev->id,
            'pin' => '1001',
        ]);

        $res->assertStatus(422);
        expect($res->json('success'))->toBeFalse();
        expect($res->json('message'))->toContain('Prohibited operation');
        expect($res->json('message'))->toContain('HRBLIZ device');
    }
});

test('DeleteBiometricUser command excludes HRBLIZ devices and refuses direct target on HRBLIZ device', function () {
    $hrblizDev = Devices::create([
        'device_name' => 'HRBLIZ Delete Target',
        'serial_number' => 'SN-HRBLIZ-DEL-01',
        'ip_address' => '192.168.1.97',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 1,
    ]);

    $stdDev = Devices::create([
        'device_name' => 'Standard Gate 1',
        'serial_number' => 'SN-STD-DEL-01',
        'ip_address' => '192.168.1.96',
        'is_active' => true,
        'is_hrbliz' => false,
        'for_attendance' => 1,
    ]);

    $cmdService = app(\App\Services\DeviceCommandService::class);

    // Direct target on HRBLIZ device must error and exit 1
    $this->artisan('biometrics:delete-user', [
        'device_sn' => 'SN-HRBLIZ-DEL-01',
        'pin' => '2002',
    ])
    ->expectsOutputToContain('Cannot delete user from device [SN-HRBLIZ-DEL-01]')
    ->assertExitCode(1);

    expect($cmdService->getAllCommands('SN-HRBLIZ-DEL-01'))->toBeEmpty();

    // Running with --all-devices must exclude HRBLIZ device and only queue to standard device
    $this->artisan('biometrics:delete-user', [
        '--all-devices' => true,
        'pin' => '2002',
    ])->assertExitCode(0);

    expect($cmdService->getAllCommands('SN-HRBLIZ-DEL-01'))->toBeEmpty();
    $stdCmds = $cmdService->getAllCommands('SN-STD-DEL-01');
    expect($stdCmds)->not->toBeEmpty();
    expect($stdCmds[0]['command'])->toContain('DATA DELETE USER PIN=2002');
});

test('BiometricSyncService generates provision commands for HRBLIZ device when receiver_by_default is 1 using hrbliz_biometric_id or fallback to biometric_id', function () {
    $hrblizDevSync = Devices::create([
        'device_name' => 'HRBLIZ Terminal Sync Enabled',
        'serial_number' => 'SN-HRBLIZ-SYNC-1',
        'ip_address' => '192.168.1.88',
        'is_active' => true,
        'is_hrbliz' => true,
        'receiver_by_default' => 1,
        'fp_version' => 'v10',
        'for_attendance' => 1,
    ]);

    $hrblizDevBlocked = Devices::create([
        'device_name' => 'HRBLIZ Terminal Sync Disabled',
        'serial_number' => 'SN-HRBLIZ-SYNC-0',
        'ip_address' => '192.168.1.89',
        'is_active' => true,
        'is_hrbliz' => true,
        'receiver_by_default' => 0,
        'fp_version' => 'v10',
        'for_attendance' => 1,
    ]);

    $userWithDualId = Biometrics::create([
        'biometric_id' => 3001,
        'hrbliz_biometric_id' => 7001,
        'name' => 'Crisostomo Ibarra',
        'privilege' => 0,
    ]);

    $userWithOnlyBioId = Biometrics::create([
        'biometric_id' => 3002,
        'hrbliz_biometric_id' => null,
        'name' => 'Elias',
        'privilege' => 0,
    ]);

    $syncService = app(BiometricSyncService::class);

    // 1. HRBLIZ device with receiver_by_default = 0 is strictly skipped (returns empty commands)
    expect($syncService->generateUserProvisionCommands($userWithDualId, true, $hrblizDevBlocked))->toBeEmpty();
    expect($syncService->generateUserProvisionCommands($userWithOnlyBioId, true, $hrblizDevBlocked))->toBeEmpty();

    // 2. HRBLIZ device with receiver_by_default = 1 uses hrbliz_biometric_id (7001)
    $cmdsDual = $syncService->generateUserProvisionCommands($userWithDualId, true, $hrblizDevSync);
    expect($cmdsDual)->not->toBeEmpty();
    expect($cmdsDual[0])->toContain('PIN=7001');

    // 3. HRBLIZ device with receiver_by_default = 1 falls back to biometric_id (3002) when hrbliz_biometric_id is null
    $cmdsBioOnly = $syncService->generateUserProvisionCommands($userWithOnlyBioId, true, $hrblizDevSync);
    expect($cmdsBioOnly)->not->toBeEmpty();
    expect($cmdsBioOnly[0])->toContain('PIN=3002');
});

test('DeviceController allows setting and updating receiver_by_default on HRBLIZ devices', function () {
    // 1. Store endpoint allows receiver_by_default = true on HRBLIZ device
    $resStore = $this->postJson('/api/devices', [
        'device_name' => 'HRBLIZ Gate Manual',
        'ip_address' => '192.168.1.155',
        'serial_number' => 'SN-HRBLIZ-STORE-1',
        'is_hrbliz' => true,
        'receiver_by_default' => true,
    ]);

    $resStore->assertStatus(201);
    $createdId = $resStore->json('data.id');
    $dev = Devices::find($createdId);
    expect($dev->is_hrbliz)->toBeTrue()
        ->and($dev->receiver_by_default)->toBeTrue()
        ->and($dev->canReceiveSync())->toBeTrue();

    // 2. Legacy endpoint allows toggling receiver_by_default to true on HRBLIZ device
    $devBlocked = Devices::create([
        'device_name' => 'HRBLIZ Gate Blocked',
        'ip_address' => '192.168.1.156',
        'serial_number' => 'SN-HRBLIZ-LEGACY-0',
        'is_hrbliz' => true,
        'receiver_by_default' => false,
    ]);

    expect($devBlocked->canReceiveSync())->toBeFalse();

    $resLegacy = $this->postJson('/api/dtr-device-updatedevicestatus', [
        'id' => $devBlocked->id,
        'field' => 'receiver_by_default',
        'value' => 1,
    ]);

    $resLegacy->assertOk();
    expect($devBlocked->fresh()->receiver_by_default)->toBeTrue()
        ->and($devBlocked->fresh()->canReceiveSync())->toBeTrue();
});

test('attendance saving correctly checks biometric_id on standard devices and hrbliz_biometric_id (with fallback) on HRBLIZ devices', function () {
    $hrblizDevice = Devices::create([
        'device_name' => 'HRBLIZ Attendance Terminal',
        'serial_number' => 'SN-HRBLIZ-ATT-99',
        'ip_address' => '192.168.10.99',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 0,
    ]);

    $stdDevice = Devices::create([
        'device_name' => 'Standard Attendance Terminal',
        'serial_number' => 'SN-STD-ATT-88',
        'ip_address' => '192.168.10.88',
        'is_active' => true,
        'is_hrbliz' => false,
        'for_attendance' => 0,
    ]);

    // Employee 1: has both biometric_id (1111) and hrbliz_biometric_id (9999)
    $empWithHrbliz = Biometrics::create([
        'biometric_id' => 1111,
        'hrbliz_biometric_id' => 9999,
        'name' => 'Dr. Jose Rizal',
        'name_with_biometric' => 'Dr. Jose Rizal [1111]',
    ]);

    // Employee 2: has only biometric_id (2222), no hrbliz_biometric_id registered
    $empWithoutHrbliz = Biometrics::create([
        'biometric_id' => 2222,
        'hrbliz_biometric_id' => null,
        'name' => 'Andres Bonifacio',
        'name_with_biometric' => 'Andres Bonifacio [2222]',
    ]);

    $repo = app(LogsRepository::class);

    // Case 1: Standard device (is_hrbliz = 0) checks biometric_id
    $logStd1 = $repo->createLog([
        'biometric_id' => 1111,
        'ip_address' => '192.168.10.88',
        'dtr_date' => '2026-09-30',
        'dtr_time' => '07:55:00',
        'dtr_type' => '0',
    ]);
    expect((int)$logStd1->biometric_id)->toBe(1111)
        ->and($logStd1->name)->toBe('Dr. Jose Rizal')
        ->and($logStd1->device_name)->toBe('Standard Attendance Terminal');

    $logStd2 = $repo->createLog([
        'biometric_id' => 2222,
        'ip_address' => '192.168.10.88',
        'dtr_date' => '2026-09-30',
        'dtr_time' => '07:58:00',
        'dtr_type' => '0',
    ]);
    expect((int)$logStd2->biometric_id)->toBe(2222)
        ->and($logStd2->name)->toBe('Andres Bonifacio')
        ->and($logStd2->device_name)->toBe('Standard Attendance Terminal');

    // Case 2: HRBLIZ device (is_hrbliz = 1) checks hrbliz_biometric_id (9999) -> resolves to canonical 1111
    $logHrb1 = $repo->createLog([
        'biometric_id' => 9999,
        'ip_address' => '192.168.10.99',
        'dtr_date' => '2026-09-30',
        'dtr_time' => '17:05:00',
        'dtr_type' => '1',
    ]);
    expect((int)$logHrb1->biometric_id)->toBe(1111)
        ->and($logHrb1->name)->toBe('Dr. Jose Rizal')
        ->and($logHrb1->device_name)->toBe('HRBLIZ Attendance Terminal');

    // Case 3: HRBLIZ device (is_hrbliz = 1) for employee with NO hrbliz_biometric_id falls back to checking biometric_id (2222)
    $logHrb2 = $repo->createLog([
        'biometric_id' => 2222,
        'ip_address' => '192.168.10.99',
        'dtr_date' => '2026-09-30',
        'dtr_time' => '17:10:00',
        'dtr_type' => '1',
    ]);
    expect((int)$logHrb2->biometric_id)->toBe(2222)
        ->and($logHrb2->name)->toBe('Andres Bonifacio')
        ->and($logHrb2->device_name)->toBe('HRBLIZ Attendance Terminal');
});

test('HRBLIZ attendance saving does not re-translate canonical ID when ID collides with another employee hrbliz_biometric_id', function () {
    if (!Schema::hasTable('attendances')) {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->integer('attendance_key')->default(1);
            $table->string('open_date')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('attendance__information')) {
        Schema::create('attendance__information', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->nullable();
            $table->string('name')->nullable();
            $table->string('area')->nullable();
            $table->string('areacode')->nullable();
            $table->string('sector')->nullable();
            $table->string('first_entry')->nullable();
            $table->string('last_entry')->nullable();
            $table->unsignedBigInteger('attendances_id')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    $att = new \App\Models\Attendance();
    $att->attendance_key = 1;
    $att->open_date = '2026-10-06';
    $att->save();

    // Employee 1: Francisco (biometric_id = 1508, hrbliz_biometric_id = 5842)
    $empFrancisco = Biometrics::create([
        'biometric_id' => 1508,
        'hrbliz_biometric_id' => 5842,
        'name' => 'Francisco, G-third',
        'name_with_biometric' => 'Francisco, G-third [1508]',
    ]);

    $ep1 = new \App\Models\EmployeeProfile();
    $ep1->biometric_id = 1508;
    $ep1->save();

    // Employee 2: Ahaja (biometric_id = 2221, hrbliz_biometric_id = 1508 - COLLISION with Francisco canonical ID!)
    $empAhaja = Biometrics::create([
        'biometric_id' => 2221,
        'hrbliz_biometric_id' => 1508,
        'name' => 'Ahaja, Norodom',
        'name_with_biometric' => 'Ahaja, Norodom [2221]',
    ]);

    $ep2 = new \App\Models\EmployeeProfile();
    $ep2->biometric_id = 2221;
    $ep2->save();

    $hrblizDevice = Devices::create([
        'device_name' => 'HRBLIZ Main Gate',
        'serial_number' => 'SN-HRBLIZ-COLLISION',
        'ip_address' => '192.168.10.99',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 1,
    ]);

    $repo = app(LogsRepository::class);

    // Francisco punches on HRBLIZ device with PIN 5842
    $log = $repo->createLog([
        'biometric_id' => 5842,
        'ip_address' => '192.168.10.99',
        'dtr_date' => '2026-10-06',
        'dtr_time' => '08:00:00',
        'dtr_type' => '0',
    ]);

    // Attendance in UMIS (AttendanceInformation) MUST belong to Francisco (1508), NOT Ahaja (2221)!
    $attInfo = \App\Models\AttendanceInformation::where('first_entry', '2026-10-06 08:00:00')->first();
    expect($attInfo)->not->toBeNull();
    expect((int)$attInfo->biometric_id)->toBe(1508);
    expect($attInfo->name)->toContain('Francisco');

    // Also test when raw_biometric_id is passed directly (as from DeviceService or LogsService)
    $logFromService = $repo->createLog([
        'biometric_id' => 1508,
        'raw_biometric_id' => 5842,
        'is_hrbliz' => true,
        'ip_address' => '192.168.10.99',
        'dtr_date' => '2026-10-06',
        'dtr_time' => '17:00:00',
        'dtr_type' => '1',
    ]);

    $attInfoOut = \App\Models\AttendanceInformation::where('first_entry', '2026-10-06 17:00:00')->first();
    expect($attInfoOut)->not->toBeNull();
    expect((int)$attInfoOut->biometric_id)->toBe(1508);
    expect($attInfoOut->name)->toContain('Francisco');
});

test('automatic Biometrics model event listeners do not auto update HRBLIZ devices with receiver_by_default = 0', function () {
    $commandService = app(DeviceCommandService::class);
    $commandService->clearCommands();

    // 1. Setup Standard device (receives sync)
    Devices::create([
        'device_name' => 'Standard Terminal',
        'serial_number' => 'SN-STD-AUTO-01',
        'ip_address' => '192.168.1.10',
        'is_active' => true,
        'is_hrbliz' => false,
        'receiver_by_default' => 1,
        'for_attendance' => 1,
    ]);

    // 2. Setup HRBLIZ device with receiver_by_default = 0 (send-only attendance terminal)
    Devices::create([
        'device_name' => 'HRBLIZ Attendance Only',
        'serial_number' => 'SN-HRBLIZ-SENDONLY-01',
        'ip_address' => '192.168.1.20',
        'is_active' => true,
        'is_hrbliz' => true,
        'receiver_by_default' => 0,
        'for_attendance' => 1,
    ]);

    // Test A: Model CREATED listener
    $templates = [
        ['Finger_ID' => '0', 'Size' => '1200', 'Valid' => '1', 'Template' => 'TMP_AUTO_001'],
    ];

    $bio = Biometrics::create([
        'biometric_id' => 9955,
        'hrbliz_biometric_id' => 8855,
        'name' => 'Model Event User',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    $stdCmds = $commandService->getAllCommands('SN-STD-AUTO-01');
    $hrblizCmds = $commandService->getAllCommands('SN-HRBLIZ-SENDONLY-01');

    // Standard device received provision commands
    expect($stdCmds)->not->toBeEmpty();
    expect($stdCmds[0]['command'])->toContain('PIN=9955');

    // HRBLIZ send-only device received absolutely NOTHING
    expect($hrblizCmds)->toBeEmpty();

    // Test B: Model UPDATED listener (name change)
    $commandService->clearCommands();
    $bio->update(['name' => 'Updated Event User']);

    $stdCmdsUpdate = $commandService->getAllCommands('SN-STD-AUTO-01');
    $hrblizCmdsUpdate = $commandService->getAllCommands('SN-HRBLIZ-SENDONLY-01');

    expect($stdCmdsUpdate)->not->toBeEmpty();
    expect($stdCmdsUpdate[0]['command'])->toContain('Name=Updated Event User');
    expect($hrblizCmdsUpdate)->toBeEmpty();

    // Test C: Model UPDATED listener (biometric template change)
    $commandService->clearCommands();
    $updatedTemplates = [
        ['Finger_ID' => '0', 'Size' => '1200', 'Valid' => '1', 'Template' => 'TMP_AUTO_001_MOD'],
        ['Finger_ID' => '1', 'Size' => '1200', 'Valid' => '1', 'Template' => 'TMP_AUTO_002_NEW'],
    ];
    $bio->update(['biometric' => json_encode($updatedTemplates)]);

    $stdCmdsBioUpdate = $commandService->getAllCommands('SN-STD-AUTO-01');
    $hrblizCmdsBioUpdate = $commandService->getAllCommands('SN-HRBLIZ-SENDONLY-01');

    expect($stdCmdsBioUpdate)->not->toBeEmpty();
    expect($hrblizCmdsBioUpdate)->toBeEmpty();

    // Test D: Model DELETED listener
    $commandService->clearCommands();
    $bio->delete();

    $stdCmdsDelete = $commandService->getAllCommands('SN-STD-AUTO-01');
    $hrblizCmdsDelete = $commandService->getAllCommands('SN-HRBLIZ-SENDONLY-01');

    expect($stdCmdsDelete)->not->toBeEmpty();
    expect($stdCmdsDelete[0]['command'])->toContain('DATA DELETE USER');
    expect($hrblizCmdsDelete)->toBeEmpty();
});

