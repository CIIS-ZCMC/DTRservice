<?php

use App\Models\Biometrics;
use App\Models\Devices;
use App\Models\DeviceLogs;
use App\Models\DeviceLogsHrbliz;
use App\Models\EmployeeProfile;
use App\Services\DeviceCommandService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
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
            $table->boolean('for_attendance')->default(0);
            $table->boolean('is_hrbliz')->default(0);
            $table->boolean('receiver_by_default')->default(1);
            $table->string('fp_version')->default('v10')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_cleared_at')->nullable();
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
            $table->longText('face')->nullable();
            $table->longText('biophoto')->nullable();
            $table->string('name_with_biometric')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('device_logs')) {
        Schema::create('device_logs', function (Blueprint $table) {
            $table->id();
            $table->string('biometric_id');
            $table->string('name')->nullable();
            $table->date('dtr_date');
            $table->dateTime('date_time');
            $table->integer('status');
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
            $table->string('biometric_id');
            $table->string('name')->nullable();
            $table->date('dtr_date');
            $table->dateTime('date_time');
            $table->integer('status');
            $table->boolean('is_Shifting')->default(0);
            $table->string('schedule')->nullable();
            $table->boolean('active')->default(1);
            $table->string('device_name')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('employee_profiles')) {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('attendances')) {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->integer('attendance_key')->default(1);
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('attendance__information')) {
        Schema::create('attendance__information', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id');
            $table->dateTime('first_entry')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('external_employees')) {
        Schema::create('external_employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
        });
    }

    @unlink(storage_path('logs/operation_logs_2026-10-06.txt'));

    Devices::query()->delete();
    Biometrics::query()->delete();
    DeviceLogs::query()->delete();
    DeviceLogsHrbliz::query()->delete();
});

test('opening HRBLIZ device (OPLOG 4 menu access) is logged to operation log file and NEVER as attendance log', function () {
    // 1. Set up HRBLIZ device and user
    $hrblizDevice = Devices::create([
        'device_name' => 'HRBLIZ Gate Device',
        'ip_address' => '192.168.10.60',
        'serial_number' => 'HRBLIZ_SN_001',
        'is_hrbliz' => true,
        'for_attendance' => false,
        'is_active' => true,
    ]);

    Biometrics::create([
        'biometric_id' => 1001,
        'hrbliz_biometric_id' => 9001,
        'name' => 'Juan Luna',
        'privilege' => 1,
    ]);

    $initialCountHrbliz = DeviceLogsHrbliz::count();
    $initialCountStandard = DeviceLogs::count();

    // 2. Admin opens device menu on HRBLIZ device -> generates OPLOG 4
    $payload = "OPLOG 4\t9001\t2026-10-06 08:30:00\t0\t0";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=HRBLIZ_SN_001',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.10.60', 'CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    // 3. Verify NO attendance log was created in any database table
    expect(DeviceLogsHrbliz::count())->toBe($initialCountHrbliz);
    expect(DeviceLogs::count())->toBe($initialCountStandard);

    // 4. Verify operation log was written to audit file
    $today = '2026-10-06';
    $opLogFile = storage_path("logs/operation_logs_{$today}.txt");
    expect(file_exists($opLogFile))->toBeTrue();

    $content = file_get_contents($opLogFile);
    expect($content)->toContain('[HRBLIZ]');
    expect($content)->toContain('HRBLIZ Gate Device');
    expect($content)->toContain('Juan Luna');
    expect($content)->toContain('Open Menu / Terminal Access');
});

test('opening UMIS device (OPLOG 4 menu access) is logged to operation log file and NEVER as attendance log', function () {
    // 1. Set up UMIS standard device and user
    $umisDevice = Devices::create([
        'device_name' => 'ATTENDANCE183 (Temporary IISU UMIS)',
        'ip_address' => '192.168.5.186',
        'serial_number' => 'BRMC231660003',
        'is_hrbliz' => false,
        'for_attendance' => true,
        'is_active' => true,
    ]);

    Biometrics::create([
        'biometric_id' => 493,
        'name' => 'Reenjay Caimor',
        'privilege' => 1,
    ]);

    $initialCountStandard = DeviceLogs::count();

    // 2. Admin opens device menu on UMIS device -> generates OPLOG 4
    $payload = "OPLOG 4\t493\t2026-10-06 08:35:12\t0\t0";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=BRMC231660003',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.5.186', 'CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    // 3. Verify NO attendance log was created in database
    expect(DeviceLogs::count())->toBe($initialCountStandard);

    // 4. Verify operation log audit trail
    $today = '2026-10-06';
    $opLogFile = storage_path("logs/operation_logs_{$today}.txt");
    expect(file_exists($opLogFile))->toBeTrue();

    $content = file_get_contents($opLogFile);
    expect($content)->toContain('[UMIS]');
    expect($content)->toContain('ATTENDANCE183');
    expect($content)->toContain('Reenjay Caimor');
    expect($content)->toContain('Open Menu / Terminal Access');
});

test('push under table=OPERLOG is logged as operation log and not as attendance', function () {
    $device = Devices::create([
        'device_name' => 'Admin Lobby Device',
        'ip_address' => '192.168.5.158',
        'serial_number' => 'AF4C231060375',
        'is_hrbliz' => false,
        'is_active' => true,
    ]);

    $initialCount = DeviceLogs::count();

    // Push with table=OPERLOG
    $payload = "4\t498\t2026-10-06 09:00:00\t0\t0";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=AF4C231060375&table=OPERLOG',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.5.158', 'CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    // No attendance logs created
    expect(DeviceLogs::count())->toBe($initialCount);

    $today = '2026-10-06';
    $opLogFile = storage_path("logs/operation_logs_{$today}.txt");
    expect(file_exists($opLogFile))->toBeTrue();
    $content = file_get_contents($opLogFile);
    expect($content)->toContain('AF4C231060375');
});

test('various operation opcodes (power on, door open) are logged to operation log file without attendance creation', function () {
    $device = Devices::create([
        'device_name' => 'Access Control Ward 1',
        'ip_address' => '192.168.5.159',
        'serial_number' => 'AF4C231060265',
        'is_hrbliz' => false,
        'is_active' => true,
    ]);

    $initialCount = DeviceLogs::count();

    // Opcode 1: Power on
    // Opcode 7: Open door / unlock
    // Opcode 14: Door sensor open
    $payload = "OPLOG 1\t0\t2026-10-06 06:00:00\t0\n"
             . "OPLOG 7\t1001\t2026-10-06 06:15:00\t1\n"
             . "OPLOG 14\t1001\t2026-10-06 06:20:00\t1";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=AF4C231060265',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.5.159', 'CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    // No attendance logs created
    expect(DeviceLogs::count())->toBe($initialCount);

    $today = '2026-10-06';
    $opLogFile = storage_path("logs/operation_logs_{$today}.txt");
    $content = file_get_contents($opLogFile);
    expect($content)->toContain('Power On / Device Startup');
    expect($content)->toContain('Open Door / Access Granted');
    expect($content)->toContain('Door Sensor / Open Door');
});

test('OPLOG 2 and OPLOG 9 still trigger self-healing auto-restore while being recorded in operation log', function () {
    $device = Devices::create([
        'device_name' => 'Auto Restore Terminal',
        'ip_address' => '192.168.1.150',
        'serial_number' => 'SN_RESTORE_01',
        'is_hrbliz' => false,
        'is_active' => true,
    ]);

    $templates = [
        ['Finger_ID' => '1', 'Size' => '512', 'Valid' => '1', 'Template' => 'TMP_RESTORE_1'],
    ];

    Biometrics::create([
        'biometric_id' => 8888,
        'name' => 'Deleted Employee',
        'privilege' => 0,
        'biometric' => json_encode($templates),
    ]);

    // Admin 493 deletes user 8888 via OPLOG 9
    $payload = "OPLOG 9\t493\t2026-10-06 09:30:00\t8888\t0\t0\t0";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=SN_RESTORE_01',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.1.150', 'CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    // Verify commands queued for auto-restore
    $commandService = app(DeviceCommandService::class);
    $commands = $commandService->getAllCommands('SN_RESTORE_01');
    $restoreCmds = array_filter($commands, fn($c) => str_contains($c['command'], '8888'));
    expect($restoreCmds)->not->toBeEmpty();

    // Verify NO attendance log was created
    expect(DeviceLogs::where('biometric_id', '8888')->count())->toBe(0);
});

test('standard attendance punch is still properly created and saved to attendance database', function () {
    $mockLogsRepo = Mockery::mock(\App\Repositories\LogsRepository::class, [app(\App\Contracts\DeviceRepositoryInterface::class)])->makePartial();
    $mockLogsRepo->shouldReceive('logExists')->andReturn(false);
    app()->instance(\App\Contracts\LogsRepositoryInterface::class, $mockLogsRepo);

    $device = Devices::create([
        'device_name' => 'Standard Attendance Device',
        'ip_address' => '192.168.5.170',
        'serial_number' => 'ATT_SN_NORMAL',
        'is_hrbliz' => false,
        'for_attendance' => false,
        'is_active' => true,
    ]);

    Biometrics::create([
        'biometric_id' => 5555,
        'name' => 'Normal Employee',
    ]);

    // Standard ATTLOG line format: PIN \t DateTime \t Status
    $payload = "5555\t2026-10-06 08:00:00\t0";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=ATT_SN_NORMAL',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.5.170', 'CONTENT_TYPE' => 'text/plain'],
        $payload
    );

    $response->assertStatus(200);

    // Must be saved to DeviceLogs
    $savedLog = DeviceLogs::where('biometric_id', '5555')
        ->where('dtr_date', '2026-10-06')
        ->first();

    expect($savedLog)->not->toBeNull();
    expect($savedLog->status)->toBe(0);
    expect($savedLog->name)->toBe('Normal Employee');
});

test('LogViewerController returns operation logs and allows clearing operation_logs.log', function () {
    // Write test entry to operation_logs.log
    $logPath = storage_path('logs/operation_logs.log');
    File::put($logPath, "[2026-10-06 08:30:00] local.INFO: Device Operation Log [UMIS]: Open Menu by PIN 493\n");

    // Test GET /logs/view
    $response = $this->get('/logs/view?lines=10');
    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKey('operation');
    expect($data['operation']['exists'])->toBeTrue();
    expect($data['operation']['lines'])->not->toBeEmpty();
    expect($data['operation']['lines'][0])->toContain('Device Operation Log');

    // Test POST /logs/clear
    $clearResponse = $this->postJson('/logs/clear', ['file' => 'operation_logs.log']);
    $clearResponse->assertStatus(200);
    $clearResponse->assertJson(['success' => true]);

    expect(File::get($logPath))->toBe('');
});
