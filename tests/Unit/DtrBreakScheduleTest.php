<?php

use App\Repositories\DtrReportRepository;
use App\Services\TimeRecordService;

uses(Tests\TestCase::class);

test('DtrReportRepository matches 7-12, 1-4 schedule with late break entries 7:04am, 1:02pm, 1:04pm, 4:00pm', function () {
    $repository = app(DtrReportRepository::class);
    $reflection = new ReflectionClass(DtrReportRepository::class);
    $method = $reflection->getMethod('matchLogsToScheduleSlots');
    $method->setAccessible(true);

    $scheduleData = [
        'first_entry' => '07:00:00',
        'second_entry' => '12:00:00',
        'third_entry' => '13:00:00',
        'last_entry' => '16:00:00',
        'is_cross_midnight' => false,
    ];

    $deviceLogs = [
        ['date_time' => '2026-06-29 07:04:00'],
        ['date_time' => '2026-06-29 13:02:00'],
        ['date_time' => '2026-06-29 13:04:00'],
        ['date_time' => '2026-06-29 16:00:00'],
    ];

    $result = $method->invoke($repository, $deviceLogs, $scheduleData, '2026-06-29');

    expect($result['first_in'])->toBe('07:04 AM');
    expect($result['first_out'])->toBe('01:02 PM');
    expect($result['second_in'])->toBe('01:04 PM');
    expect($result['second_out'])->toBe('04:00 PM');
});

test('TimeRecordService supports late break entries 7:04am, 1:02pm, 1:04pm, 4:00pm', function () {
    $service = app(TimeRecordService::class);
    $schedule = ['07:00:00', '12:00:00', '13:00:00', '16:00:00'];
    $entries = [
        '2026-06-29 07:04:00',
        '2026-06-29 13:02:00',
        '2026-06-29 13:04:00',
        '2026-06-29 16:00:00',
    ];

    $valid = $service->getEntriesWithinSchedule($entries, $schedule);
    expect($valid['entries'])->toHaveCount(4);
    expect($valid['entries'])->toEqual($entries);

    $formatted = $service->formatEntries($valid['entries'], $schedule, '2026-06-29');
    expect($formatted['first_in'])->toBe('2026-06-29 07:04:00');
    expect($formatted['first_out'])->toBe('2026-06-29 13:02:00');
    expect($formatted['second_in'])->toBe('2026-06-29 13:04:00');
    expect($formatted['second_out'])->toBe('2026-06-29 16:00:00');

    $workingHours = $service->ComputeWorkingHours($formatted, $schedule);
    expect($workingHours['required_working_minutes'])->toBe(480);
    expect($workingHours['actual_working_minutes'])->toBe(472);
    expect($workingHours['undertime_minutes'])->toBe(8);
    expect($workingHours['overtime_minutes'])->toBe(0);
});

test('filterByIntervals supports 7:04am, 1:02pm, 1:04pm, 4:00pm entries', function () {
    $repository = app(DtrReportRepository::class);
    $reflection = new ReflectionClass(DtrReportRepository::class);
    $method = $reflection->getMethod('filterByIntervals');
    $method->setAccessible(true);

    $deviceLogs = [
        ['date_time' => '2026-06-29 07:04:00'],
        ['date_time' => '2026-06-29 13:02:00'],
        ['date_time' => '2026-06-29 13:04:00'],
        ['date_time' => '2026-06-29 16:00:00'],
    ];

    $result = $method->invoke($repository, $deviceLogs);

    expect($result['first_in'])->toBe('07:04 AM');
    expect($result['first_out'])->toBe('01:02 PM');
    expect($result['second_in'])->toBe('01:04 PM');
    expect($result['second_out'])->toBe('04:00 PM');
});

test('DtrReportRepository handles single break punch correctly', function () {
    $repository = app(DtrReportRepository::class);
    $reflection = new ReflectionClass(DtrReportRepository::class);
    $method = $reflection->getMethod('matchLogsToScheduleSlots');
    $method->setAccessible(true);

    $scheduleData = [
        'first_entry' => '07:00:00',
        'second_entry' => '12:00:00',
        'third_entry' => '13:00:00',
        'last_entry' => '16:00:00',
        'is_cross_midnight' => false,
    ];

    // Single break punch closer to second_in (1:02 PM)
    $logs1 = [
        ['date_time' => '2026-06-29 07:00:00'],
        ['date_time' => '2026-06-29 13:02:00'],
        ['date_time' => '2026-06-29 16:00:00'],
    ];
    $result1 = $method->invoke($repository, $logs1, $scheduleData, '2026-06-29');
    expect($result1['first_in'])->toBe('07:00 AM');
    expect($result1['first_out'])->toBeNull();
    expect($result1['second_in'])->toBe('01:02 PM');
    expect($result1['second_out'])->toBe('04:00 PM');

    // Single break punch closer to first_out (12:15 PM)
    $logs2 = [
        ['date_time' => '2026-06-29 07:00:00'],
        ['date_time' => '2026-06-29 12:15:00'],
        ['date_time' => '2026-06-29 16:00:00'],
    ];
    $result2 = $method->invoke($repository, $logs2, $scheduleData, '2026-06-29');
    expect($result2['first_in'])->toBe('07:00 AM');
    expect($result2['first_out'])->toBe('12:15 PM');
    expect($result2['second_in'])->toBeNull();
    expect($result2['second_out'])->toBe('04:00 PM');
});

test('TimeRecordService matches entries up to 3 hours and 30 mins (210 mins) window from schedule', function () {
    $service = app(TimeRecordService::class);
    $schedule = ['08:00:00', '12:00:00', '13:00:00', '17:00:00'];

    // 04:40:00 is 3 hours 20 minutes (200 minutes) before 08:00:00 (within 210m window, beyond old 180m window)
    // 20:20:00 is 3 hours 20 minutes (200 minutes) after 17:00:00 (within 210m window, beyond old 180m window)
    $entries = [
        '2026-06-29 04:40:00',
        '2026-06-29 12:00:00',
        '2026-06-29 13:00:00',
        '2026-06-29 20:20:00',
    ];

    $valid = $service->getEntriesWithinSchedule($entries, $schedule);
    expect($valid['entries'])->toHaveCount(4);
    expect($valid['entries'])->toEqual($entries);

    $formatted = $service->formatEntries($valid['entries'], $schedule, '2026-06-29');
    expect($formatted['first_in'])->toBe('2026-06-29 04:40:00');
    expect($formatted['first_out'])->toBe('2026-06-29 12:00:00');
    expect($formatted['second_in'])->toBe('2026-06-29 13:00:00');
    expect($formatted['second_out'])->toBe('2026-06-29 20:20:00');
});

test('DtrReportRepository matches logs within 3 hours 30 mins (12600s) threshold', function () {
    $repository = app(DtrReportRepository::class);
    $reflection = new ReflectionClass(DtrReportRepository::class);
    $method = $reflection->getMethod('matchLogsToScheduleSlots');
    $method->setAccessible(true);

    $scheduleData = [
        'first_entry' => '08:00:00',
        'second_entry' => '12:00:00',
        'third_entry' => '13:00:00',
        'last_entry' => '17:00:00',
        'is_cross_midnight' => false,
    ];

    // Single break punch at 16:20 (3 hours 20 mins after third_entry 13:00, within 12600s, outside old 10800s)
    $deviceLogs = [
        ['date_time' => '2026-06-29 08:00:00'],
        ['date_time' => '2026-06-29 16:20:00'],
        ['date_time' => '2026-06-29 17:00:00'],
    ];

    $result = $method->invoke($repository, $deviceLogs, $scheduleData, '2026-06-29');
    expect($result['first_in'])->toBe('08:00 AM');
    expect($result['second_in'])->toBe('04:20 PM');
    expect($result['second_out'])->toBe('05:00 PM');
});
