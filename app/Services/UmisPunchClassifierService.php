<?php

namespace App\Services;

use App\Models\DeviceLogs;
use App\Models\DeviceLogsHrbliz;
use App\Models\EmployeeProfile;
use App\Models\Schedule;
use App\Models\TimeShifts;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UmisPunchClassifierService
{
    protected ?TimeRecordService $timeRecordService;

    public function __construct(?TimeRecordService $timeRecordService = null)
    {
        $this->timeRecordService = $timeRecordService ?? app(TimeRecordService::class);
    }

    /**
     * Classify an attendance punch timestamp into strictly 0 (Check-In) or 1 (Check-Out)
     * using UMIS slot assignment (formatEntries), employee shift schedule, and chronological sequence rules.
     *
     * @param int|string $biometricId Canonical biometric ID (UMIS PIN)
     * @param string $dateTime Full timestamp (YYYY-MM-DD HH:MM:SS)
     * @param array $knownDayPunches Optional array of already known/batched timestamps for today
     * @return int 0 for Check-In, 1 for Check-Out
     */
    public function classifyStatus(int|string $biometricId, string $dateTime, array $knownDayPunches = []): int
    {
        $bioId = (int)$biometricId;

        try {
            $punchCarbon = Carbon::parse($dateTime);
        } catch (\Throwable $e) {
            $punchCarbon = now();
        }

        $date = $punchCarbon->format('Y-m-d');
        $currentDtStr = $punchCarbon->format('Y-m-d H:i:s');

        // 1. Fetch all distinct punches for this employee today up to the current timestamp
        $dayPunches = $this->getDayPunchesUntil($bioId, $date, $punchCarbon, $knownDayPunches);

        // 2. Resolve employee schedule times (today's schedule -> latest schedule -> system default)
        $scheduleTimes = $this->resolveEmployeeScheduleTimes($bioId, $date);

        // 3. Run UMIS formatEntries on all punches of the day
        if (!empty($scheduleTimes) && !empty($dayPunches)) {
            $classified = $this->classifyViaUmisSlots($dayPunches, $scheduleTimes, $date, $punchCarbon);
            if ($classified !== null) {
                return $classified;
            }
        }

        // 4. Fallback: Strict chronological alternation (1st=0/In, 2nd=1/Out, 3rd=0/In, 4th=1/Out)
        $punchIndex = array_search($currentDtStr, $dayPunches, true);
        if ($punchIndex === false) {
            $punchIndex = count($dayPunches) - 1;
        }

        return ($punchIndex % 2 === 0) ? 0 : 1;
    }

    /**
     * Run UMIS formatEntries on the accumulated punches of the day and find which slot the target punch belongs to.
     *
     * @param array $dayPunches Chronologically sorted datetime strings of the day
     * @param array $scheduleTimes ['08:00:00', '12:00:00', '13:00:00', '17:00:00']
     * @param string $date YYYY-MM-DD
     * @param Carbon $punchCarbon
     * @return int|null 0 (In), 1 (Out), or null if not allocated
     */
    protected function classifyViaUmisSlots(array $dayPunches, array $scheduleTimes, string $date, Carbon $punchCarbon): ?int
    {
        try {
            $slots = $this->timeRecordService->formatEntries($dayPunches, $scheduleTimes, $date);

            $targetMinute = $punchCarbon->copy()->startOfMinute()->toDateTimeString();

            foreach ($slots as $slotKey => $slotVal) {
                if (!empty($slotVal) && $slotVal === $targetMinute) {
                    // first_in or second_in -> 0 (Check-In)
                    // first_out or second_out -> 1 (Check-Out)
                    return in_array($slotKey, ['first_in', 'second_in'], true) ? 0 : 1;
                }
            }

            // If startOfMinute didn't match directly, check diff within 1 minute
            foreach ($slots as $slotKey => $slotVal) {
                if (!empty($slotVal)) {
                    $slotCarbon = Carbon::parse($slotVal);
                    if (abs($punchCarbon->diffInMinutes($slotCarbon)) <= 1) {
                        return in_array($slotKey, ['first_in', 'second_in'], true) ? 0 : 1;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('classifyViaUmisSlots error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Retrieve all chronological punch timestamps for this employee on this date up to $punchCarbon.
     *
     * @param int $bioId
     * @param string $date
     * @param Carbon $punchCarbon
     * @param array $knownDayPunches
     * @return array<string> Sorted datetime strings (Y-m-d H:i:s)
     */
    protected function getDayPunchesUntil(int $bioId, string $date, Carbon $punchCarbon, array $knownDayPunches = []): array
    {
        $cutoffStr = $punchCarbon->format('Y-m-d H:i:s');
        $entries = [];

        // Include any caller-supplied known punches up to cutoff
        foreach ($knownDayPunches as $kp) {
            try {
                $kpCarbon = Carbon::parse($kp);
                if ($kpCarbon->format('Y-m-d') === $date && $kpCarbon->format('Y-m-d H:i:s') <= $cutoffStr) {
                    $entries[] = $kpCarbon->format('Y-m-d H:i:s');
                }
            } catch (\Throwable $e) {
                // Ignore parse errors
            }
        }

        try {
            if (Schema::hasTable('device_logs')) {
                $logs = DeviceLogs::where('biometric_id', $bioId)
                    ->where('dtr_date', $date)
                    ->where('date_time', '<=', $cutoffStr)
                    ->orderBy('date_time', 'asc')
                    ->pluck('date_time')
                    ->all();
                $entries = array_merge($entries, $logs);
            }

            if (Schema::hasTable('device_logs_hrbliz')) {
                $hrbLogs = DeviceLogsHrbliz::where('biometric_id', $bioId)
                    ->where('dtr_date', $date)
                    ->where('date_time', '<=', $cutoffStr)
                    ->orderBy('date_time', 'asc')
                    ->pluck('date_time')
                    ->all();
                $entries = array_merge($entries, $hrbLogs);
            }
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('getDayPunchesUntil error: ' . $e->getMessage());
        }

        // Ensure the current punch itself is included
        if (!in_array($cutoffStr, $entries, true)) {
            $entries[] = $cutoffStr;
        }

        // De-duplicate and sort ascending
        $entries = array_values(array_unique($entries));
        sort($entries);

        return $entries;
    }

    /**
     * Resolve employee's schedule times:
     * 1) Today's assigned schedule
     * 2) Employee's most recent schedule in UMIS
     * 3) Default system schedule (08:00 - 12:00 - 13:00 - 17:00)
     *
     * @param int $biometricId
     * @param string $date
     * @return array
     */
    protected function resolveEmployeeScheduleTimes(int $biometricId, string $date): array
    {
        if ($biometricId >= 8000) {
            // External employees use default 4-slot or external schedules
            return [
                config('app.default_first_in', '08:00:00'),
                config('app.default_first_out', '12:00:00'),
                config('app.default_last_in', '13:00:00'),
                config('app.default_last_out', '17:00:00'),
            ];
        }

        // 1. Check for specific schedule today
        $timeShift = $this->getEmployeeTimeShift($biometricId, $date);

        // 2. If no schedule on this specific date, get the employee's latest active schedule
        if (!$timeShift && Schema::hasTable('employee_profiles') && Schema::hasTable('schedules')) {
            try {
                $profile = EmployeeProfile::where('biometric_id', $biometricId)
                    ->whereNull('deleted_at')
                    ->first();

                if ($profile) {
                    $latestSched = Schedule::whereHas('employeeSchedules', function ($q) use ($profile) {
                        $q->where('employee_profile_id', $profile->id);
                    })
                    ->whereNull('deleted_at')
                    ->with('timeShift')
                    ->orderBy('date', 'desc')
                    ->first();

                    $timeShift = $latestSched?->timeShift;
                }
            } catch (\Throwable $e) {
                Log::channel('device_logs')->debug('resolveEmployeeScheduleTimes fallback error: ' . $e->getMessage());
            }
        }

        // If we have a timeShift model, extract its schedule times
        if ($timeShift) {
            if (!empty($timeShift->second_in) || !empty($timeShift->second_out)) {
                // 4-slot regular schedule
                return array_filter([
                    $timeShift->first_in,
                    $timeShift->first_out,
                    $timeShift->second_in,
                    $timeShift->second_out,
                ]);
            } else {
                // 2-slot shifting schedule
                return array_filter([
                    $timeShift->first_in,
                    $timeShift->first_out,
                ]);
            }
        }

        // 3. Fallback to system default 4-slot schedule from config
        return [
            config('app.default_first_in', '08:00:00'),
            config('app.default_first_out', '12:00:00'),
            config('app.default_last_in', '13:00:00'),
            config('app.default_last_out', '17:00:00'),
        ];
    }

    /**
     * Fetch active TimeShift for employee on a given date.
     */
    protected function getEmployeeTimeShift(int $biometricId, string $date): ?TimeShifts
    {
        if ($biometricId >= 8000 || !Schema::hasTable('employee_profiles') || !Schema::hasTable('schedules')) {
            return null;
        }

        try {
            $profileIds = EmployeeProfile::where('biometric_id', $biometricId)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->toArray();

            if (empty($profileIds)) {
                return null;
            }

            $schedule = Schedule::where('date', $date)
                ->whereNull('deleted_at')
                ->whereHas('employeeSchedules', function ($query) use ($profileIds) {
                    $query->whereIn('employee_profile_id', $profileIds);
                })
                ->with('timeShift')
                ->orderBy('id', 'desc')
                ->first();

            return $schedule?->timeShift;
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('getEmployeeTimeShift error: ' . $e->getMessage());
            return null;
        }
    }
}
