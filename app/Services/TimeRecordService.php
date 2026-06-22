<?php

namespace App\Services;

use App\Contracts\TimeRecordRepositoryInterface;
use App\Traits\TimeFormatter;
use Carbon\Carbon;

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
            'is_shifting'=>empty( $timeShift?->second_out) && empty($timeShift?->second_in)
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

        if($timeRecord['is_shifting']) {
            return $this->consolidateShiftingTimeRecord($timeRecord);
        }

        $entries = collect($timeRecord['device_logs'])->map(function($row){
            return Carbon::parse($row['date_time'])->toTimeString();
        });

        $schedule = isset($timeRecord['schedule']) ? $timeRecord['schedule'] : null;
        if($schedule) {
            $timeShift = $timeRecord['time_shift'];
            $scheduleTimes = [
                $timeShift->first_in,
                $timeShift->first_out,
                $timeShift->second_in,
                $timeShift->second_out,
            ];

     $validEntries = $this->getEntriesWithinSchedule($entries->toArray(), $scheduleTimes);
     $workingHours = $this->ComputeWorkingHours($validEntries['entries'],$scheduleTimes);
     $formattedEntries = $this->formatEntries($validEntries['entries'],$scheduleTimes);
     
    return [
         'biometric_id' => $biometricId,
        ...$formattedEntries,
        'dtr_date'=>$date,
        'interval_req'=>null,
        'required_working_hours'=>$workingHours['required_working_minutes'] > 0 ? floor($workingHours['required_working_minutes'] / 60) : 0,
        'required_working_minutes'=>$workingHours['required_working_minutes'],
        'total_working_hours'=>$workingHours['actual_working_minutes'] > 0 ? floor($workingHours['actual_working_minutes'] / 60) : 0,
        'total_working_minutes'=>$workingHours['actual_working_minutes'],
        'overtime'=>$this->minutesToHoursMinutes($workingHours['overtime_minutes']),
        'overtime_minutes'=>$workingHours['overtime_minutes'],
        'undertime'=>$this->minutesToHoursMinutes($workingHours['undertime_minutes']),
        'undertime_minutes'=>$workingHours['undertime_minutes'],
        'overall_minutes_rendered'=>$workingHours['actual_working_minutes'],
        'total_minutes_reg'=>$workingHours['required_working_minutes'],
        'is_biometric'=>1,
        'is_time_adjustment'=>0
    ];
    
     // $this->timeRecordRepository->saveDailyTimeRecord([
    //      'biometric_id',
    //     'first_in',
    //     'first_out',
    //     'second_in',
    //     'second_out',
    //     'dtr_date',
    //     'interval_req',
    //     'required_working_hours',
    //     'required_working_minutes',
    //     'total_working_hours',
    //     'total_working_minutes',
    //     'overtime',
    //     'overtime_minutes',
    //     'undertime',
    //     'undertime_minutes',
    //     'overall_minutes_rendered',
    //     'total_minutes_reg',
    //     'is_biometric',
    //     'is_time_adjustment'
    // ]);
    
        }
        return $schedule;
    }

    public function consolidateShiftingTimeRecord(array $timeRecord)
    {
        // TODO: Implement shifting logic

        $timeShift = $timeRecord['time_shift'];
        $scheduleTimes = [
            $timeShift->first_in,
            $timeShift->first_out,
        ];

        return [
            'timeRecord'=>$timeRecord['device_logs'],
            'scheduleTimes'=>$scheduleTimes
        ];
    }

    public function getEntriesWithinSchedule(array $entries, array $schedule)
    {
        $results = [];
        $matchedSchedules = []; 
        $lastMatchedTime = null;
        $windowHours = 3;
        $intervalMinutes = 5;

        foreach ($entries as $entry) {
            $entryTime = Carbon::parse($entry);
            $matched = false;
            $matchedSchedule = null;
            $status = false;
            $reason = 'not within schedule window';

            // Find matching schedule time
            foreach ($schedule as $scheduleTime) {
                $scheduleCarbon = Carbon::parse($scheduleTime);

                // Check if entry is within 3-hour window of schedule
                $windowStart = $scheduleCarbon->copy()->subHours($windowHours);
                $windowEnd = $scheduleCarbon->copy()->addHours($windowHours);

                if ($entryTime->between($windowStart, $windowEnd)) {
                    // Check if this schedule time has already been matched
                    if (!in_array($scheduleTime, $matchedSchedules)) {
                        // Check if 5+ minutes from last matched entry
                        if ($lastMatchedTime === null || abs($entryTime->diffInMinutes($lastMatchedTime)) >= $intervalMinutes) {
                            $matched = true;
                            $matchedSchedule = $scheduleTime;
                            $status = true;
                            $reason = 'within 3-hour window - match schedule';
                            $matchedSchedules[] = $scheduleTime;
                            $lastMatchedTime = $entryTime;
                            break;
                        } else {
                            $reason = 'not within 5 mins from recent - match schedule but invalid';
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
        // Schedule boundaries (ignore seconds)
        $schedFirstIn = Carbon::parse($schedule[0])->startOfMinute();
        $schedFirstOut = Carbon::parse($schedule[1])->startOfMinute();
        $schedSecondIn = Carbon::parse($schedule[2])->startOfMinute();
        $schedSecondOut = Carbon::parse($schedule[3])->startOfMinute();

        $requiredFirstShift = abs($schedFirstOut->diffInMinutes($schedFirstIn));
        $requiredSecondShift = abs($schedSecondOut->diffInMinutes($schedSecondIn));
        $requiredTotalMinutes = $requiredFirstShift + $requiredSecondShift;

        // Match entries to schedule times in order (ignore seconds)
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
        if ($matchedEntries[2] && $matchedEntries[3]) {
            $effIn = $matchedEntries[2]->greaterThan($schedSecondIn) ? $matchedEntries[2] : $schedSecondIn;
            $effOut = $matchedEntries[3]->lessThan($schedSecondOut) ? $matchedEntries[3] : $schedSecondOut;
            if ($effOut->greaterThan($effIn)) {
                $regularSecond = abs($effOut->diffInMinutes($effIn));
            }
        }

        $regularMinutes = $regularFirst + $regularSecond;

        // Overtime: time worked beyond scheduled second_out
        $overtimeMinutes = 0;
        if ($matchedEntries[3] && $matchedEntries[3]->greaterThan($schedSecondOut)) {
            $overtimeMinutes = abs($matchedEntries[3]->diffInMinutes($schedSecondOut));
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

    public function formatEntries(array $entries, array $schedule)
    {


        $slots = [
            'first_in' => null,
            'first_out' => null,
            'second_in' => null,
            'second_out' => null,
        ];

        if(count($schedule) < 4) {
            return $this->formatEntriesOfShifter($entries, $schedule);
        }

        $slotKeys = ['first_in', 'first_out', 'second_in', 'second_out'];
        $windowMinutes = 180; // 3 hours

        // Normalize schedule onto a continuous timeline so night shifts that
        // cross midnight (e.g. 22:00 -> 02:00) remain monotonically increasing.
        $base = Carbon::parse($schedule[0])->startOfMinute();
        $scheduleTimes = [$base->copy()];

        for ($i = 1; $i < 4; $i++) {
            $t = Carbon::parse($schedule[$i])->startOfMinute()
                ->setDate($base->year, $base->month, $base->day);
            while ($t->lt($scheduleTimes[$i - 1])) {
                $t->addDay();
            }
            $scheduleTimes[$i] = $t;
        }

        $pointer = 0;
        $isFirst = true;
        $prevEntryTime = $base->copy()->subMinutes($windowMinutes);

        foreach ($entries as $entry) {
            // Normalize entry onto the same continuous timeline (keep chronological)
            $entryTime = Carbon::parse($entry)->startOfMinute()
                ->setDate($base->year, $base->month, $base->day);
            while ($entryTime->lt($prevEntryTime)) {
                $entryTime->addDay();
            }
            $prevEntryTime = $entryTime->copy();

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
                    $slots[$slotKeys[$targetIndex]] = $entry;
                    $pointer = $targetIndex + 1;
                }
            }

            $isFirst = false;
        }

        return $slots;
    }

    public function formatEntriesOfShifter(array $entries, array $schedule)
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

        // Normalize schedule onto a continuous timeline
        $base = Carbon::parse($schedule[0])->startOfMinute();
        $scheduleTimes = [$base->copy()];
        for ($i = 1; $i < $slotCount; $i++) {
            $t = Carbon::parse($schedule[$i])->startOfMinute()
                ->setDate($base->year, $base->month, $base->day);
            while ($t->lt($scheduleTimes[$i - 1])) {
                $t->addDay();
            }
            $scheduleTimes[$i] = $t;
        }

        $pointer = 0;
        $isFirst = true;
        $prevEntryTime = $base->copy()->subMinutes($windowMinutes);

        foreach ($entries as $entry) {
            $entryTime = Carbon::parse($entry)->startOfMinute()
                ->setDate($base->year, $base->month, $base->day);
            while ($entryTime->lt($prevEntryTime)) {
                $entryTime->addDay();
            }
            $prevEntryTime = $entryTime->copy();

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
                    $slots[$slotKeys[$targetIndex]] = $entry;
                    $pointer = $targetIndex + 1;
                }
            }

            $isFirst = false;
        }

        return $slots;
    }

    
}
