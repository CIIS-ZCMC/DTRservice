<?php

use App\Repositories\DtrReportRepository;
use App\Models\Schedule;
use App\Models\TimeShifts;
use App\Models\ExternalSchedule;

uses(Tests\TestCase::class);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function () {
    if (!Schema::hasTable('holidays')) {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('month_day')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('biometrics')) {
        Schema::create('biometrics', function (Blueprint $table) {
            $table->id();
            $table->integer('biometric_id')->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('employee_profiles')) {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->integer('biometric_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('external_employees')) {
        Schema::create('external_employees', function (Blueprint $table) {
            $table->id();
            $table->integer('biometric_id')->nullable();
            $table->timestamps();
        });
    }
});

test('buildScheduleData correctly identifies 22:00 to 06:00 as cross midnight shift', function () {
    $repository = new DtrReportRepository();
    $reflection = new ReflectionClass(DtrReportRepository::class);
    $method = $reflection->getMethod('buildScheduleData');
    $method->setAccessible(true);

    $schedule = new Schedule(['date' => '2026-08-01']);
    $timeShift = new TimeShifts([
        'first_in' => '22:00:00',
        'first_out' => '06:00:00',
        'second_in' => null,
        'second_out' => null,
    ]);

    $result = $method->invoke($repository, $schedule, $timeShift);

    expect($result['is_cross_midnight'])->toBeTrue();
    expect($result['first_entry'])->toBe('22:00:00');
    expect($result['last_entry'])->toBe('06:00:00');
});

test('generateReport correctly marks next day as DAY OFF when prev day is 10pm-6am cross-midnight and next day has no schedule', function () {
    $repository = Mockery::mock(DtrReportRepository::class)->makePartial();

    $schedOct15 = new ExternalSchedule([
        'dtr_date' => '2026-10-15',
        'first_in' => '22:00:00',
        'first_out' => '06:00:00',
        'second_in' => null,
        'second_out' => null,
        'is_shifting' => true,
    ]);

    $mockData = [
        'employee' => [
            'biometric_id' => 1234,
            'name' => 'Test Employee',
        ],
        'records' => [
            [
                'date' => '2026-10-15',
                'schedule' => $schedOct15,
                'time_shift' => null,
                'device_logs' => [
                    ['dtr_date' => '2026-10-15', 'date_time' => '2026-10-15 20:27:00'],
                ],
            ],
            [
                'date' => '2026-10-16',
                'schedule' => null,
                'time_shift' => null,
                'device_logs' => [
                    ['dtr_date' => '2026-10-16', 'date_time' => '2026-10-16 06:13:00'],
                    ['dtr_date' => '2026-10-16', 'date_time' => '2026-10-16 06:13:20'],
                ],
            ],
        ],
    ];

    $repository->shouldReceive('getEmployeeDtrData')->andReturn($mockData);

    $report = $repository->generateReport(1234, '2026-10-15', '2026-10-16');

    $records = $report['daily_records'];
    expect($records)->toHaveCount(2);

    // Oct 15
    expect($records[0]['dtr_date'])->toBe('2026-10-15');
    expect($records[0]['second_in'])->toBe('08:27 PM');

    // Oct 16 (Next day has no schedule, 2 punches in morning)
    expect($records[1]['dtr_date'])->toBe('2026-10-16');
    expect($records[1]['first_out'])->toBe('06:13 AM');
    expect($records[1]['first_in'])->toBe('DAY OFF');
    expect($records[1]['has_schedule'])->toBeEmpty();
    expect($records[1]['remarks'])->toBeNull();
});

test('generateReport correctly marks next day as DAY OFF even with 0 logs when prev day is cross-midnight', function () {
    $repository = Mockery::mock(DtrReportRepository::class)->makePartial();

    $schedOct15 = new ExternalSchedule([
        'dtr_date' => '2026-10-15',
        'first_in' => '22:00:00',
        'first_out' => '06:00:00',
        'second_in' => null,
        'second_out' => null,
        'is_shifting' => true,
    ]);

    $mockData = [
        'employee' => [
            'biometric_id' => 1234,
            'name' => 'Test Employee',
        ],
        'records' => [
            [
                'date' => '2026-10-15',
                'schedule' => $schedOct15,
                'time_shift' => null,
                'device_logs' => [
                    ['dtr_date' => '2026-10-15', 'date_time' => '2026-10-15 20:27:00'],
                ],
            ],
            [
                'date' => '2026-10-16',
                'schedule' => null,
                'time_shift' => null,
                'device_logs' => [],
            ],
        ],
    ];

    $repository->shouldReceive('getEmployeeDtrData')->andReturn($mockData);

    $report = $repository->generateReport(1234, '2026-10-15', '2026-10-16');

    $records = $report['daily_records'];
    expect($records)->toHaveCount(2);

    expect($records[1]['dtr_date'])->toBe('2026-10-16');
    expect($records[1]['first_out'])->toBeNull();
    expect($records[1]['first_in'])->toBe('DAY OFF');
    expect($records[1]['has_schedule'])->toBeEmpty();
});

test('generateReport correctly matches today schedule when prev day is cross-midnight and today has duty schedule', function () {
    $repository = Mockery::mock(DtrReportRepository::class)->makePartial();

    $schedOct15 = new ExternalSchedule([
        'dtr_date' => '2026-10-15',
        'first_in' => '22:00:00',
        'first_out' => '06:00:00',
        'second_in' => null,
        'second_out' => null,
        'is_shifting' => true,
    ]);

    $schedOct16 = new ExternalSchedule([
        'dtr_date' => '2026-10-16',
        'first_in' => '14:00:00',
        'first_out' => null,
        'second_in' => null,
        'second_out' => '22:00:00',
        'is_shifting' => true,
    ]);

    $mockData = [
        'employee' => [
            'biometric_id' => 1234,
            'name' => 'Test Employee',
        ],
        'records' => [
            [
                'date' => '2026-10-15',
                'schedule' => $schedOct15,
                'time_shift' => null,
                'device_logs' => [
                    ['dtr_date' => '2026-10-15', 'date_time' => '2026-10-15 20:27:00'],
                ],
            ],
            [
                'date' => '2026-10-16',
                'schedule' => $schedOct16,
                'time_shift' => null,
                'device_logs' => [
                    ['dtr_date' => '2026-10-16', 'date_time' => '2026-10-16 06:13:00'],
                    ['dtr_date' => '2026-10-16', 'date_time' => '2026-10-16 13:55:00'],
                    ['dtr_date' => '2026-10-16', 'date_time' => '2026-10-16 22:05:00'],
                ],
            ],
        ],
    ];

    $repository->shouldReceive('getEmployeeDtrData')->andReturn($mockData);

    $report = $repository->generateReport(1234, '2026-10-15', '2026-10-16');

    $records = $report['daily_records'];
    expect($records)->toHaveCount(2);

    // Oct 16: has schedule, so first_out gets the 6:13 AM out punch from Oct 15 night shift,
    // and today's schedule punches are matched (second_in / second_out)
    expect($records[1]['dtr_date'])->toBe('2026-10-16');
    expect($records[1]['first_out'])->toBe('06:13 AM');
    expect($records[1]['first_in'])->toBeNull();
    expect($records[1]['second_in'])->toBe('01:55 PM');
    expect($records[1]['second_out'])->toBe('10:05 PM');
    expect($records[1]['has_schedule'])->toHaveCount(1);
});


