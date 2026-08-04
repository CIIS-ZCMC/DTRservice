<?php

use App\Repositories\DtrReportRepository;
use App\Models\Schedule;
use App\Models\TimeShifts;

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
