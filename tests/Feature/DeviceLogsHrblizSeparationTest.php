<?php

use App\Contracts\DtrReportRepositoryInterface;
use App\Contracts\LogsRepositoryInterface;
use App\Contracts\TimeRecordRepositoryInterface;
use App\Repositories\LogsRepository;
use App\Http\Controllers\DeviceLogAlertController;
use App\Models\Biometrics;
use App\Models\DeviceLogs;
use App\Models\DeviceLogsHrbliz;
use App\Models\Devices;
use App\Models\DTR;
use App\Models\EmployeeProfile;
use App\Models\PersonalInformation;
use App\Services\TimeRecordService;
use Illuminate\Http\Request;
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

    if (!Schema::hasTable('personal_informations')) {
        Schema::create('personal_informations', function (Blueprint $table) {
            $table->id();
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

    if (!Schema::hasTable('daily_time_records')) {
        Schema::create('daily_time_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_id');
            $table->string('first_in')->nullable();
            $table->string('first_out')->nullable();
            $table->string('second_in')->nullable();
            $table->string('second_out')->nullable();
            $table->string('dtr_date')->nullable();
            $table->integer('interval_req')->nullable();
            $table->integer('required_working_hours')->nullable();
            $table->integer('required_working_minutes')->nullable();
            $table->integer('total_working_hours')->nullable();
            $table->integer('total_working_minutes')->nullable();
            $table->integer('overtime')->nullable();
            $table->integer('overtime_minutes')->nullable();
            $table->integer('undertime')->nullable();
            $table->integer('undertime_minutes')->nullable();
            $table->integer('overall_minutes_rendered')->nullable();
            $table->integer('total_minutes_reg')->nullable();
            $table->boolean('is_biometric')->default(1);
            $table->boolean('is_time_adjustment')->default(0);
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

    Devices::query()->delete();
    Biometrics::query()->delete();
    DeviceLogs::query()->delete();
    DeviceLogsHrbliz::query()->delete();
    DTR::query()->delete();
});

test('accepting HRBLIZ attendance log saves to device_logs_hrbliz and NOT device_logs', function () {
    $mockLogsRepo = Mockery::mock(LogsRepository::class, [app(\App\Contracts\DeviceRepositoryInterface::class)])->makePartial();
    $mockLogsRepo->shouldReceive('logExists')->andReturn(false);
    app()->instance(\App\Contracts\LogsRepositoryInterface::class, $mockLogsRepo);

    $hrblizDevice = Devices::create([
        'device_name' => 'HRBLIZ Main Entrance',
        'serial_number' => 'SN-HRBLIZ-MAIN',
        'ip_address' => '192.168.10.50',
        'is_active' => true,
        'is_hrbliz' => true,
        'for_attendance' => 0,
    ]);

    $stdDevice = Devices::create([
        'device_name' => 'ZKTeco Standard Terminal',
        'serial_number' => 'SN-STD-MAIN',
        'ip_address' => '192.168.10.51',
        'is_active' => true,
        'is_hrbliz' => false,
        'for_attendance' => 0,
    ]);

    Biometrics::create([
        'biometric_id' => 1001,
        'hrbliz_biometric_id' => 9001,
        'name' => 'Juan Luna',
        'privilege' => 0,
    ]);

    // Push punch from HRBLIZ device via /iclock/cdata
    $hrblizPunch = "9001\t2026-10-05 08:00:00\t0";
    $resHrb = $this->call('POST', '/iclock/cdata?SN=SN-HRBLIZ-MAIN', [], [], [], [
        'REMOTE_ADDR' => '192.168.10.50',
        'CONTENT_TYPE' => 'text/plain',
    ], $hrblizPunch);
    $resHrb->assertStatus(200);

    // Push punch from Standard device via /iclock/cdata
    $stdPunch = "1001\t2026-10-05 17:00:00\t1";
    $resStd = $this->call('POST', '/iclock/cdata?SN=SN-STD-MAIN', [], [], [], [
        'REMOTE_ADDR' => '192.168.10.51',
        'CONTENT_TYPE' => 'text/plain',
    ], $stdPunch);
    $resStd->assertStatus(200);

    // HRBLIZ log must be in device_logs_hrbliz table only
    $hrbRecord = DeviceLogsHrbliz::where('biometric_id', 1001)->first();
    expect($hrbRecord)->not->toBeNull()
        ->and($hrbRecord->device_name)->toBe('HRBLIZ Main Entrance')
        ->and($hrbRecord->date_time)->toBe('2026-10-05 08:00:00')
        ->and($hrbRecord->status)->toBe('0');

    expect(DeviceLogs::where('device_name', 'HRBLIZ Main Entrance')->count())->toBe(0);

    // Standard log must be in device_logs table only
    $stdRecord = DeviceLogs::where('biometric_id', 1001)->first();
    expect($stdRecord)->not->toBeNull()
        ->and($stdRecord->device_name)->toBe('ZKTeco Standard Terminal')
        ->and($stdRecord->date_time)->toBe('2026-10-05 17:00:00')
        ->and($stdRecord->status)->toBe('1');

    expect(DeviceLogsHrbliz::where('device_name', 'ZKTeco Standard Terminal')->count())->toBe(0);
});

test('Device Log Alert shows HRBLIZ logs in scanDatabase, dateEntries, and previewPrintLogs', function () {
    $hrblizDevice = Devices::create([
        'device_name' => 'HRBLIZ Turnstile West',
        'serial_number' => 'SN-HRBLIZ-WEST',
        'ip_address' => '192.168.20.10',
        'is_active' => true,
        'is_hrbliz' => true,
    ]);

    DeviceLogs::create([
        'biometric_id' => 2001,
        'name' => 'Standard Employee',
        'dtr_date' => '2026-10-05',
        'date_time' => '2026-10-05 08:05:00',
        'status' => '0',
        'device_name' => 'Standard Main Gate',
    ]);

    DeviceLogsHrbliz::create([
        'biometric_id' => 2002,
        'name' => 'HRBLIZ Employee',
        'dtr_date' => '2026-10-05',
        'date_time' => '2026-10-05 08:15:00',
        'status' => '0',
        'device_name' => 'HRBLIZ Turnstile West',
    ]);

    $controller = app(DeviceLogAlertController::class);

    // 1. scanDatabase should aggregate counts from both tables
    $scanRes = $controller->scanDatabase(Request::create('/logs/alert/scan-db', 'GET'));
    $scanData = $scanRes->getData(true);
    expect($scanData['dates']['2026-10-05']['count'])->toBe(2);

    // 2. dateEntries should list entries from both tables
    $entriesRes = $controller->dateEntries(Request::create('/logs/alert/date/2026-10-05', 'GET'), '2026-10-05');
    $entriesData = $entriesRes->getData(true);
    expect($entriesData['total'])->toBe(2);

    $hrbEntry = collect($entriesData['entries'])->firstWhere('biometric_id', '2002');
    expect($hrbEntry)->not->toBeNull()
        ->and($hrbEntry['is_hrbliz'])->toBeTrue()
        ->and($hrbEntry['device_name'])->toBe('HRBLIZ Turnstile West');

    $stdEntry = collect($entriesData['entries'])->firstWhere('biometric_id', '2001');
    expect($stdEntry)->not->toBeNull()
        ->and($stdEntry['is_hrbliz'])->toBeFalse();

    // 3. previewPrintLogs should show both
    $previewReq = Request::create('/logs/alert/preview-print', 'POST', [
        'dates' => ['2026-10-05'],
    ]);
    $previewRes = $controller->previewPrintLogs($previewReq);
    $previewData = $previewRes->getData(true);
    expect($previewData['total'])->toBe(2);

    // 4. generateDeviceLogs saves HRBLIZ to DeviceLogsHrbliz
    $genReq = Request::create('/logs/alert/generate-device-logs', 'POST', [
        'entries' => [
            [
                'biometric_id' => '3001',
                'name' => 'Gen User HRB',
                'dtr_date' => '2026-10-06',
                'dtr_time' => '09:00:00',
                'dtr_type' => '0',
                'device_name' => 'HRBLIZ Turnstile West',
            ],
            [
                'biometric_id' => '3002',
                'name' => 'Gen User STD',
                'dtr_date' => '2026-10-06',
                'dtr_time' => '09:10:00',
                'dtr_type' => '0',
                'device_name' => 'Standard Gate 1',
            ]
        ]
    ]);
    $genRes = $controller->generateDeviceLogs($genReq);
    expect($genRes->getData(true)['created_count'])->toBe(2);

    expect(DeviceLogsHrbliz::where('biometric_id', '3001')->exists())->toBeTrue();
    expect(DeviceLogs::where('biometric_id', '3001')->exists())->toBeFalse();

    expect(DeviceLogs::where('biometric_id', '3002')->exists())->toBeTrue();
    expect(DeviceLogsHrbliz::where('biometric_id', '3002')->exists())->toBeFalse();
});

test('dtr-self and Daily time records display HRBLIZ logs seamlessly', function () {
    $empBio = Biometrics::create([
        'biometric_id' => 5555,
        'name' => 'Gabriela Silang',
        'privilege' => 0,
    ]);

    // Morning check-in on HRBLIZ device
    DeviceLogsHrbliz::create([
        'biometric_id' => 5555,
        'name' => 'Gabriela Silang',
        'dtr_date' => '2026-10-05',
        'date_time' => '2026-10-05 08:00:00',
        'status' => '0',
        'device_name' => 'HRBLIZ Entrance',
    ]);

    // Afternoon check-out on Standard device
    DeviceLogs::create([
        'biometric_id' => 5555,
        'name' => 'Gabriela Silang',
        'dtr_date' => '2026-10-05',
        'date_time' => '2026-10-05 17:00:00',
        'status' => '1',
        'device_name' => 'Standard Exit',
    ]);

    // 1. TimeRecordRepository returns both logs
    $timeRepo = app(TimeRecordRepositoryInterface::class);
    $timeLogs = $timeRepo->getDeviceLogsByBiometricId(5555, '2026-10-05', '2026-10-05');
    expect($timeLogs)->toHaveCount(2);

    $employeesWithLogs = $timeRepo->getEmployeesWithDeviceLogs('2026-10-05', '2026-10-05');
    expect($employeesWithLogs)->toContain(5555);

    // 2. dtr-self API endpoint returns both logs in deviceLogs.logs
    $selfResponse = $this->call('GET', '/api/dtr-self?biometric_id=5555&date=2026-10-05');
    $selfResponse->assertStatus(200);
    $selfData = $selfResponse->json('data');
    expect($selfData['deviceLogs']['logs'])->toHaveCount(2);
    expect($selfData['first_in'])->toBe('08:00 am');
    expect($selfData['first_out'])->toBe('05:00 pm');

    // 3. DtrReportRepository monthly DTR data returns both logs
    $dtrRepo = app(DtrReportRepositoryInterface::class);
    $monthlyDtr = $dtrRepo->getEmployeeDtrData(5555, '2026-10-01', '2026-10-31');
    $dayRecord = collect($monthlyDtr['records'])->firstWhere('date', '2026-10-05');
    expect($dayRecord['device_logs'])->toHaveCount(2);
});

test('pruneLogs removes old logs from both device_logs and device_logs_hrbliz', function () {
    $logsRepo = app(LogsRepositoryInterface::class);
    $cutoff = now()->subYear()->format('Y-m-d');

    $oldDate = now()->subYear()->subDays(5)->format('Y-m-d');
    $recentDate = now()->subMonths(3)->format('Y-m-d');

    // Old logs
    DeviceLogs::create([
        'biometric_id' => 8001,
        'name' => 'Old STD',
        'dtr_date' => $oldDate,
        'date_time' => "{$oldDate} 08:00:00",
        'status' => '0',
    ]);

    DeviceLogsHrbliz::create([
        'biometric_id' => 8002,
        'name' => 'Old HRB',
        'dtr_date' => $oldDate,
        'date_time' => "{$oldDate} 08:00:00",
        'status' => '0',
    ]);

    // Recent logs
    DeviceLogs::create([
        'biometric_id' => 8003,
        'name' => 'Recent STD',
        'dtr_date' => $recentDate,
        'date_time' => "{$recentDate} 08:00:00",
        'status' => '0',
    ]);

    DeviceLogsHrbliz::create([
        'biometric_id' => 8004,
        'name' => 'Recent HRB',
        'dtr_date' => $recentDate,
        'date_time' => "{$recentDate} 08:00:00",
        'status' => '0',
    ]);

    $result = $logsRepo->pruneLogs($cutoff, 2000, false, false);
    expect($result['deleted_count'])->toBe(2);

    expect(DeviceLogs::where('biometric_id', 8001)->exists())->toBeFalse();
    expect(DeviceLogsHrbliz::where('biometric_id', 8002)->exists())->toBeFalse();

    expect(DeviceLogs::where('biometric_id', 8003)->exists())->toBeTrue();
    expect(DeviceLogsHrbliz::where('biometric_id', 8004)->exists())->toBeTrue();
});
