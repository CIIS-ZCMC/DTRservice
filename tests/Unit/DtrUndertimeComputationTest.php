<?php

use App\Repositories\DtrReportRepository;

uses(Tests\TestCase::class);

function getCalculateUndertimeMethod() {
    $repository = app(DtrReportRepository::class);
    $reflection = new ReflectionClass(DtrReportRepository::class);
    $method = $reflection->getMethod('calculateUndertime');
    $method->setAccessible(true);
    return [$repository, $method];
}

test('split schedule computes morning and afternoon late arrival and early departure correctly', function () {
    [$repository, $method] = getCalculateUndertimeMethod();

    $schedule = [
        'first_entry' => '08:00:00',
        'second_entry' => '12:00:00',
        'third_entry' => '13:00:00',
        'last_entry' => '17:00:00',
    ];

    // Case 1: 15 mins late in morning (08:15 AM)
    $slots1 = [
        'first_in' => '08:15 AM',
        'first_out' => '12:00 PM',
        'second_in' => '01:00 PM',
        'second_out' => '05:00 PM',
    ];
    expect($method->invoke($repository, $slots1, $schedule))->toBe(15);

    // Case 2: 10 mins early departure at lunch (11:50 AM)
    $slots2 = [
        'first_in' => '08:00 AM',
        'first_out' => '11:50 AM',
        'second_in' => '01:00 PM',
        'second_out' => '05:00 PM',
    ];
    expect($method->invoke($repository, $slots2, $schedule))->toBe(10);

    // Case 3: 20 mins late after lunch (01:20 PM)
    $slots3 = [
        'first_in' => '08:00 AM',
        'first_out' => '12:00 PM',
        'second_in' => '01:20 PM',
        'second_out' => '05:00 PM',
    ];
    expect($method->invoke($repository, $slots3, $schedule))->toBe(20);

    // Case 4: 15 mins early departure end of day (04:45 PM)
    $slots4 = [
        'first_in' => '08:00 AM',
        'first_out' => '12:00 PM',
        'second_in' => '01:00 PM',
        'second_out' => '04:45 PM',
    ];
    expect($method->invoke($repository, $slots4, $schedule))->toBe(15);

    // Case 5: Cumulative lates and undertime in one day (10 late AM + 5 early AM + 5 late PM + 10 early PM = 30)
    $slots5 = [
        'first_in' => '08:10 AM',
        'first_out' => '11:55 AM',
        'second_in' => '01:05 PM',
        'second_out' => '04:50 PM',
    ];
    expect($method->invoke($repository, $slots5, $schedule))->toBe(30);

    // Case 6: Perfect attendance
    $slots6 = [
        'first_in' => '08:00 AM',
        'first_out' => '12:00 PM',
        'second_in' => '01:00 PM',
        'second_out' => '05:00 PM',
    ];
    expect($method->invoke($repository, $slots6, $schedule))->toBeNull();
});

test('single pair morning schedule computes late arrival and early departure correctly', function () {
    [$repository, $method] = getCalculateUndertimeMethod();

    $schedule = [
        'first_entry' => '08:00:00',
        'second_entry' => null,
        'third_entry' => null,
        'last_entry' => '16:00:00',
    ];

    // Case 1: 25 mins late arrival (08:25 AM)
    $slots1 = [
        'first_in' => '08:25 AM',
        'first_out' => null,
        'second_in' => null,
        'second_out' => '04:00 PM',
    ];
    expect($method->invoke($repository, $slots1, $schedule))->toBe(25);

    // Case 2: 15 mins early departure (03:45 PM)
    $slots2 = [
        'first_in' => '08:00 AM',
        'first_out' => null,
        'second_in' => null,
        'second_out' => '03:45 PM',
    ];
    expect($method->invoke($repository, $slots2, $schedule))->toBe(15);

    // Case 3: Both late and early out (08:15 AM and 03:50 PM = 15 + 10 = 25)
    $slots3 = [
        'first_in' => '08:15 AM',
        'first_out' => null,
        'second_in' => null,
        'second_out' => '03:50 PM',
    ];
    expect($method->invoke($repository, $slots3, $schedule))->toBe(25);
});

test('single pair PM shifting schedule computes late arrival and early departure correctly', function () {
    [$repository, $method] = getCalculateUndertimeMethod();

    $schedule = [
        'first_entry' => '14:00:00',
        'second_entry' => null,
        'third_entry' => null,
        'last_entry' => '22:00:00',
    ];

    // PM shifting schedule maps logs to second_in and second_out
    // 20 mins late arrival (02:20 PM)
    $slots1 = [
        'first_in' => null,
        'first_out' => null,
        'second_in' => '02:20 PM',
        'second_out' => '10:00 PM',
    ];
    expect($method->invoke($repository, $slots1, $schedule))->toBe(20);

    // 15 mins early departure (09:45 PM)
    $slots2 = [
        'first_in' => null,
        'first_out' => null,
        'second_in' => '02:00 PM',
        'second_out' => '09:45 PM',
    ];
    expect($method->invoke($repository, $slots2, $schedule))->toBe(15);

    // Both late and early departure (10 late + 10 early = 20)
    $slots3 = [
        'first_in' => null,
        'first_out' => null,
        'second_in' => '02:10 PM',
        'second_out' => '09:50 PM',
    ];
    expect($method->invoke($repository, $slots3, $schedule))->toBe(20);
});

test('single pair cross-midnight schedule computes late arrival and early departure correctly', function () {
    [$repository, $method] = getCalculateUndertimeMethod();

    $schedule = [
        'first_entry' => '22:00:00',
        'second_entry' => null,
        'third_entry' => null,
        'last_entry' => '06:00:00',
    ];

    // Case 1: 15 mins late arrival (10:15 PM)
    $slots1 = [
        'first_in' => null,
        'first_out' => null,
        'second_in' => '10:15 PM',
        'second_out' => '06:00 AM',
    ];
    expect($method->invoke($repository, $slots1, $schedule))->toBe(15);

    // Case 2: 15 mins early departure next morning (05:45 AM)
    $slots2 = [
        'first_in' => null,
        'first_out' => null,
        'second_in' => '10:00 PM',
        'second_out' => '05:45 AM',
    ];
    expect($method->invoke($repository, $slots2, $schedule))->toBe(15);

    // Case 3: Early departure BEFORE midnight (11:30 PM / 23:30) -> 390 mins undertime
    $slots3 = [
        'first_in' => null,
        'first_out' => null,
        'second_in' => '10:00 PM',
        'second_out' => '11:30 PM',
    ];
    expect($method->invoke($repository, $slots3, $schedule))->toBe(390);

    // Case 4: On time
    $slots4 = [
        'first_in' => null,
        'first_out' => null,
        'second_in' => '10:00 PM',
        'second_out' => '06:00 AM',
    ];
    expect($method->invoke($repository, $slots4, $schedule))->toBeNull();
});

test('non-time values bypass undertime calculation', function () {
    [$repository, $method] = getCalculateUndertimeMethod();

    $schedule = [
        'first_entry' => '08:00:00',
        'second_entry' => '12:00:00',
        'third_entry' => '13:00:00',
        'last_entry' => '17:00:00',
    ];

    expect($method->invoke($repository, ['first_in' => 'ABSENT', 'first_out' => null, 'second_in' => null, 'second_out' => null], $schedule))->toBeNull();
    expect($method->invoke($repository, ['first_in' => 'DAY OFF', 'first_out' => null, 'second_in' => null, 'second_out' => null], $schedule))->toBeNull();
    expect($method->invoke($repository, ['first_in' => 'LV', 'first_out' => null, 'second_in' => null, 'second_out' => null], $schedule))->toBeNull();
});
