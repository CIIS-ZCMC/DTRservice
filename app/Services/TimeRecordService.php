<?php

namespace App\Services;

use App\Contracts\TimeRecordRepositoryInterface;
use App\Traits\TimeFormatter;
use Carbon\Carbon;

use function Pest\Laravel\json;

class TimeRecordService
{
    use TimeFormatter;

    public function __construct(
        protected TimeRecordRepositoryInterface $timeRecordRepository
    ) {}

    /**
     * Get employee time record for a specific date
     */
    public function getEmployeeTimeRecord(int $biometricId, string $date): array
    {
        $deviceLogs = $this->timeRecordRepository->getDeviceLogsByBiometricId($biometricId, $date, $date);
        $schedule = $this->timeRecordRepository->getEmployeeSchedule($biometricId, $date);
        $timeShift = $this->timeRecordRepository->getTimeShiftByDate($biometricId, $date);

        return [
            'biometric_id' => $biometricId,
            'date' => $date,
            'device_logs' => $deviceLogs,
            'schedule' => $schedule,
            'time_shift' => $timeShift,
            'is_shifting' => $timeShift && empty($timeShift->second_out) && empty($timeShift->second_in)
        ];
    }

    /**
     * Get employee time records for a date range
     */
    public function getEmployeeTimeRecordsRange(int $biometricId, string $dateFrom, string $dateTo): array
    {
        $deviceLogs = $this->timeRecordRepository->getDeviceLogsByBiometricId($biometricId, $dateFrom, $dateTo);
        $records = [];
        foreach ($deviceLogs as $log) {
            $date = $log['dtr_date'];
            if (!isset($records[$date])) {
                $records[$date] = [
                    'date' => $date,
                    'device_logs' => [],
                    'schedule' => $this->timeRecordRepository->getEmployeeSchedule($biometricId, $date),
                    'time_shift' => $this->timeRecordRepository->getTimeShiftByDate($biometricId, $date),
                ];
            }
            $records[$date]['device_logs'][] = $log;
        }

        return array_values($records);
    }

    /**
     * Consolidate daily time records into DailytimeRecord format
     */
    public function consolidateDailyTimeRecord(int $biometricId, string $date)
    {
        $timeRecord = $this->getEmployeeTimeRecord($biometricId, $date);
        $is_next_day = false;
        $scheduleTimes = [];
        $schedule = [];

        // No schedule/time shift for this date - nothing to consolidate
        if (empty($timeRecord['time_shift'])) {
            return;
        }

        if ($timeRecord['is_shifting']) {
            [
                $entries,
                $scheduleTimes,
                $is_next_day
            ] = $this->consolidateShiftingTimeRecord($timeRecord, $is_next_day);
        } else {
            $entries = collect($timeRecord['device_logs'])->map(function ($row) {
                return Carbon::parse($row['date_time'])->toDateTimeString();
            });

            $schedule = isset($timeRecord['schedule']) ? $timeRecord['schedule'] : null;
            if ($schedule) {
                $timeShift = $timeRecord['time_shift'];
                $scheduleTimes = [
                    $timeShift->first_in,
                    $timeShift->first_out,
                    $timeShift->second_in,
                    $timeShift->second_out,
                ];
            }
        }

        // Filter out null values from scheduleTimes
        $scheduleTimes = array_filter($scheduleTimes, fn($value) => $value !== null);

        $validEntries = $this->getEntriesWithinSchedule($entries->toArray(), $scheduleTimes);
        $workingHours = $this->ComputeWorkingHours($validEntries['entries'], $scheduleTimes);
        $formattedEntries = $this->formatEntries($validEntries['entries'], $scheduleTimes, $date, $is_next_day);

        $dtrData = [
            'biometric_id' => $biometricId,
            ...$formattedEntries,
            'dtr_date' => $date,
            'interval_req' => null,
            'required_working_hours' => $workingHours['required_working_minutes'] > 0 ? floor($workingHours['required_working_minutes'] / 60) : 0,
            'required_working_minutes' => $workingHours['required_working_minutes'],
            'total_working_hours' => $workingHours['actual_working_minutes'] > 0 ? floor($workingHours['actual_working_minutes'] / 60) : 0,
            'total_working_minutes' => $workingHours['actual_working_minutes'],
            'overtime' => $this->minutesToHoursMinutes($workingHours['overtime_minutes']),
            'overtime_minutes' => $workingHours['overtime_minutes'],
            'undertime' => $this->minutesToHoursMinutes($workingHours['undertime_minutes']),
            'undertime_minutes' => $workingHours['undertime_minutes'],
            'overall_minutes_rendered' => $workingHours['actual_working_minutes'],
            'total_minutes_reg' => $workingHours['required_working_minutes'],
            'is_biometric' => 1,
            'is_time_adjustment' => 0
        ];

        if (empty(array_filter($formattedEntries, fn($value) => $value !== null))) {
            return;
        }

        $this->timeRecordRepository->saveDailyTimeRecord($dtrData);
    }

    public function consolidateShiftingTimeRecord(array $timeRecord, bool &$is_next_day)
    {
        $timeShift = $timeRecord['time_shift'];
        $date = $timeRecord['date'];
        $biometricId = $timeRecord['biometric_id'];

        if (!$timeShift) {
            return [collect(), [], $is_next_day];
        }

        $scheduleTimes = [
            $timeShift->first_in,
            $timeShift->first_out,
        ];
        $allLogs = $timeRecord['device_logs'];

        // Detect cross-midnight shift: first_out is earlier than first_in
        // (e.g. 22:00 in -> 06:00 out, 14:00 in -> 22:00 out is same-day)
        $firstInTime = Carbon::parse($timeShift->first_in);
        $firstOutTime = Carbon::parse($timeShift->first_out);
        $crossesMidnight = $firstOutTime->lessThan($firstInTime);

        // Also check shifts starting at or after 17:00 as a secondary heuristic
        $startsInEvening = $firstInTime->format('H') >= 17;

        if ($crossesMidnight || $startsInEvening) {
            $nextDay = Carbon::parse($date)->addDay()->format('Y-m-d');
            $nextDayLogs = $this->timeRecordRepository->getDeviceLogsByBiometricId($biometricId, $nextDay, $nextDay);
            $allLogs = array_merge($timeRecord['device_logs'], $nextDayLogs);
            $is_next_day = true;
        }
        $entries = collect($allLogs)->map(function ($row) {
            return Carbon::parse($row['date_time'])->toDateTimeString();
        });

        return [$entries, $scheduleTimes, $is_next_day];
    }

    public function getEntriesWithinSchedule(array $entries, array $schedule)
    {
        $results = [];
        $matchedScheduleIndices = [];
        $lastMatchedTime = null;
        $windowHours = 3;
        $intervalMinutes = 3;

        // Build a continuous timeline for schedule times based on the first entry's date
        // so cross-midnight schedules (e.g. 22:00 -> 06:00) are correctly ordered.
        $baseDate = !empty($entries) ? Carbon::parse($entries[0])->startOfDay() : now()->startOfDay();
        $scheduleTimes = [];
        $scheduleTimes[] = Carbon::parse($schedule[0])->startOfMinute()->setDateFrom($baseDate);
        for ($i = 1; $i < count($schedule); $i++) {
            $t = Carbon::parse($schedule[$i])->startOfMinute()->setDateFrom($baseDate);
            while ($t->lt($scheduleTimes[$i - 1])) {
                $t->addDay();
            }
            $scheduleTimes[] = $t;
        }

        foreach ($entries as $entry) {
            $entryTime = Carbon::parse($entry);
            $matched = false;
            $matchedSchedule = null;
            $status = false;
            $reason = 'not within schedule window';

            // Find matching schedule time
            foreach ($scheduleTimes as $idx => $scheduleCarbon) {
                // Check if entry is within 3-hour window of schedule
                $windowStart = $scheduleCarbon->copy()->subHours($windowHours);
                $windowEnd = $scheduleCarbon->copy()->addHours($windowHours);

                if ($entryTime->between($windowStart, $windowEnd)) {
                    // Check if this schedule time has already been matched
                    if (!in_array($idx, $matchedScheduleIndices)) {
                        // Check if 3+ minutes from last matched entry
                        if ($lastMatchedTime === null || abs($entryTime->diffInMinutes($lastMatchedTime)) >= $intervalMinutes) {
                            $matched = true;
                            $matchedSchedule = $schedule[$idx];
                            $status = true;
                            $reason = 'within 3-hour window - match schedule';
                            $matchedScheduleIndices[] = $idx;
                            $lastMatchedTime = $entryTime;
                            break;
                        } else {
                            $reason = 'not within 3 mins from recent - match schedule but invalid';
                        }
                    }
                }
            }

            $results[] = [
                'entry' => $entry,
                'is_included' => $status,
                'reason' => $reason,
                'matched_schedule' => $matchedSchedule,
            ];
        }

        return [
            'results' => $results,
            'schedule' => $schedule,
            'entries' => collect($results)->where('is_included', true)->pluck('entry')->toArray(),
        ];
    }

    public function ComputeWorkingHours(array $entries, array $schedule): array
    {
        if (empty($schedule)) {
            return [
                'required_working_minutes' => 0,
                'actual_working_minutes' => 0,
                'overtime_minutes' => 0,
                'undertime_minutes' => 0,
                'entries' => $entries,
                'schedule' => $schedule,
            ];
        }

        // Build a continuous timeline for schedule based on the first entry's date
        // so cross-midnight schedules (e.g. 22:00 -> 06:00) are correctly ordered.
        $baseDate = !empty($entries) ? Carbon::parse($entries[0])->startOfDay() : now()->startOfDay();
        $base = Carbon::parse($schedule[0])->startOfMinute()->setDateFrom($baseDate);
        $scheduleTimes = [$base->copy()];
        for ($i = 1; $i < count($schedule); $i++) {
            $t = Carbon::parse($schedule[$i])->startOfMinute()->setDateFrom($baseDate);
            while ($t->lt($scheduleTimes[$i - 1])) {
                $t->addDay();
            }
            $scheduleTimes[$i] = $t;
        }

        // Schedule boundaries
        $schedFirstIn = $scheduleTimes[0];
        $schedFirstOut = $scheduleTimes[1];
        $schedSecondIn = isset($scheduleTimes[2]) ? $scheduleTimes[2] : null;
        $schedSecondOut = isset($scheduleTimes[3]) ? $scheduleTimes[3] : null;

        $requiredFirstShift = abs($schedFirstOut->diffInMinutes($schedFirstIn));
        $requiredSecondShift = $schedSecondIn && $schedSecondOut ? abs($schedSecondOut->diffInMinutes($schedSecondIn)) : 0;
        $requiredTotalMinutes = $requiredFirstShift + $requiredSecondShift;

        // Entries are now full datetimes - parse directly, no date normalization needed
        $matchedEntries = [];
        for ($i = 0; $i < 4; $i++) {
            $matchedEntries[$i] = isset($entries[$i]) ? Carbon::parse($entries[$i])->startOfMinute() : null;
        }

        // Regular worked minutes - clamp each segment to schedule boundaries
        $regularFirst = 0;
        if ($matchedEntries[0] && $matchedEntries[1]) {
            $effIn = $matchedEntries[0]->greaterThan($schedFirstIn) ? $matchedEntries[0] : $schedFirstIn;
            $effOut = $matchedEntries[1]->lessThan($schedFirstOut) ? $matchedEntries[1] : $schedFirstOut;
            if ($effOut->greaterThan($effIn)) {
                $regularFirst = abs($effOut->diffInMinutes($effIn));
            }
        }

        $regularSecond = 0;
        if ($matchedEntries[2] && $matchedEntries[3] && $schedSecondIn && $schedSecondOut) {
            $effIn = $matchedEntries[2]->greaterThan($schedSecondIn) ? $matchedEntries[2] : $schedSecondIn;
            $effOut = $matchedEntries[3]->lessThan($schedSecondOut) ? $matchedEntries[3] : $schedSecondOut;
            if ($effOut->greaterThan($effIn)) {
                $regularSecond = abs($effOut->diffInMinutes($effIn));
            }
        }

        $regularMinutes = $regularFirst + $regularSecond;

        // Overtime: time worked beyond scheduled out time
        // For 4-slot schedules: check second_out
        // For 2-slot shifting schedules: check first_out
        $overtimeMinutes = 0;
        if ($schedSecondOut && $matchedEntries[3] && $matchedEntries[3]->greaterThan($schedSecondOut)) {
            $overtimeMinutes = abs($matchedEntries[3]->diffInMinutes($schedSecondOut));
        }
        if (!$schedSecondOut && $matchedEntries[1] && $matchedEntries[1]->greaterThan($schedFirstOut)) {
            $overtimeMinutes = abs($matchedEntries[1]->diffInMinutes($schedFirstOut));
        }

        // Undertime: required regular minutes not fulfilled within schedule
        $undertimeMinutes = $requiredTotalMinutes - $regularMinutes;
        if ($undertimeMinutes < 0) {
            $undertimeMinutes = 0;
        }

        return [
            'required_working_minutes' => $requiredTotalMinutes,
            'actual_working_minutes' => $regularMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'entries' => $entries,
            'schedule' => $schedule,
        ];
    }

    public function formatEntries(array $entries, array $schedule, string $date = null, bool $isNextDay = false)
    {
        $slots = [
            'first_in' => null,
            'first_out' => null,
            'second_in' => null,
            'second_out' => null,
        ];

        if (empty($schedule)) {
            return $slots;
        }

        if (count($schedule) < 4) {
            return $this->formatEntriesOfShifter($entries, $schedule, $date ?? now()->format('Y-m-d'), $isNextDay);
        }

        $slotKeys = ['first_in', 'first_out', 'second_in', 'second_out'];
        $windowMinutes = 180; // 3 hours

        // Build continuous timeline for schedule based on first entry's date
        $baseDate = !empty($entries) ? Carbon::parse($entries[0])->startOfDay() : Carbon::parse($date ?? 'now')->startOfDay();
        $base = Carbon::parse($schedule[0])->startOfMinute()->setDateFrom($baseDate);
        $scheduleTimes = [$base->copy()];

        for ($i = 1; $i < 4; $i++) {
            $t = Carbon::parse($schedule[$i])->startOfMinute()->setDateFrom($baseDate);
            while ($t->lt($scheduleTimes[$i - 1])) {
                $t->addDay();
            }
            $scheduleTimes[$i] = $t;
        }

        $pointer = 0;
        $isFirst = true;

        foreach ($entries as $entry) {
            // Entries are now full datetimes - use directly
            $entryTime = Carbon::parse($entry)->startOfMinute();

            $targetIndex = null;

            if ($isFirst) {
                // First entry: place in the CLOSEST schedule slot within window
                $bestDiff = null;
                for ($i = 0; $i < 4; $i++) {
                    $diff = abs($entryTime->diffInMinutes($scheduleTimes[$i]));
                    if ($diff <= $windowMinutes && ($bestDiff === null || $diff < $bestDiff)) {
                        $bestDiff = $diff;
                        $targetIndex = $i;
                    }
                }
            } else {
                // Subsequent entries: place in the FIRST slot from pointer within window
                for ($i = $pointer; $i < 4; $i++) {
                    $diff = abs($entryTime->diffInMinutes($scheduleTimes[$i]));
                    if ($diff <= $windowMinutes) {
                        $targetIndex = $i;
                        break;
                    }
                }
            }

            // Fallback: first empty slot from pointer onward
            if ($targetIndex === null) {
                for ($i = $pointer; $i < 4; $i++) {
                    if ($slots[$slotKeys[$i]] === null) {
                        $targetIndex = $i;
                        break;
                    }
                }
            }

            // Place the entry, skipping any already-filled slots
            if ($targetIndex !== null) {
                while ($targetIndex < 4 && $slots[$slotKeys[$targetIndex]] !== null) {
                    $targetIndex++;
                }

                if ($targetIndex < 4) {
                    $slots[$slotKeys[$targetIndex]] = $entryTime->toDateTimeString();
                    $pointer = $targetIndex + 1;
                }
            }

            $isFirst = false;
        }

        return $slots;
    }

    public function formatEntriesOfShifter(array $entries, array $schedule, string $date, bool $isNextDay = false)
    {
        $slotCount = count($schedule);
        $slotKeys = ['first_in', 'first_out', 'second_in', 'second_out'];

        $slots = [
            'first_in' => null,
            'first_out' => null,
            'second_in' => null,
            'second_out' => null,
        ];
        $windowMinutes = 180; // 3 hours

        // Build continuous timeline for schedule based on first entry's date
        $baseDate = !empty($entries) ? Carbon::parse($entries[0])->startOfDay() : Carbon::parse($date)->startOfDay();
        $base = Carbon::parse($schedule[0])->startOfMinute()->setDateFrom($baseDate);
        $scheduleTimes = [$base->copy()];
        for ($i = 1; $i < $slotCount; $i++) {
            $t = Carbon::parse($schedule[$i])->startOfMinute()->setDateFrom($baseDate);
            while ($t->lt($scheduleTimes[$i - 1])) {
                $t->addDay();
            }
            $scheduleTimes[$i] = $t;
        }

        $pointer = 0;
        $isFirst = true;

        foreach ($entries as $entry) {
            // Entries are now full datetimes - use directly
            $entryTime = Carbon::parse($entry)->startOfMinute();

            $targetIndex = null;

            if ($isFirst) {
                $bestDiff = null;
                for ($i = 0; $i < $slotCount; $i++) {
                    $diff = abs($entryTime->diffInMinutes($scheduleTimes[$i]));
                    if ($diff <= $windowMinutes && ($bestDiff === null || $diff < $bestDiff)) {
                        $bestDiff = $diff;
                        $targetIndex = $i;
                    }
                }
            } else {
                for ($i = $pointer; $i < $slotCount; $i++) {
                    $diff = abs($entryTime->diffInMinutes($scheduleTimes[$i]));
                    if ($diff <= $windowMinutes) {
                        $targetIndex = $i;
                        break;
                    }
                }
            }

            if ($targetIndex === null) {
                for ($i = $pointer; $i < $slotCount; $i++) {
                    if ($slots[$slotKeys[$i]] === null) {
                        $targetIndex = $i;
                        break;
                    }
                }
            }

            if ($targetIndex !== null) {
                while ($targetIndex < $slotCount && $slots[$slotKeys[$targetIndex]] !== null) {
                    $targetIndex++;
                }

                if ($targetIndex < $slotCount) {
                    $slots[$slotKeys[$targetIndex]] = $entryTime->toDateTimeString();
                    $pointer = $targetIndex + 1;
                }
            }

            $isFirst = false;
        }

        return $slots;
    }
}
