<?php

namespace App\Services;

use App\Models\DeviceLogs;
use App\Models\EmployeeProfile;
use App\Models\Schedule;
use App\Models\TimeShifts;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UmisPunchClassifierService
{
    /**
     * Window in minutes around scheduled time (3 hours and 30 mins, matching UMIS standard)
     */
    protected int $windowMinutes = 210;

    /**
     * Minimum interval in minutes to distinguish distinct punches
     */
    protected int $minIntervalMinutes = 3;

    /**
     * Classify an attendance punch timestamp into strictly 0 (Check-In) or 1 (Check-Out)
     * using UMIS shift schedules, slot windows, and chronological sequence rules.
     *
     * @param int|string $biometricId Canonical biometric ID (UMIS PIN)
     * @param string $dateTime Full timestamp (YYYY-MM-DD HH:MM:SS)
     * @return int 0 for Check-In, 1 for Check-Out
     */
    public function classifyStatus(int|string $biometricId, string $dateTime): int
    {
        $bioId = (int)$biometricId;

        try {
            $punchCarbon = Carbon::parse($dateTime);
        } catch (\Throwable $e) {
            $punchCarbon = now();
        }

        $date = $punchCarbon->format('Y-m-d');

        // 1. Try to resolve employee schedule & time shift from UMIS
        $timeShift = $this->getEmployeeTimeShift($bioId, $date);

        if ($timeShift) {
            $classified = $this->classifyByTimeShift($timeShift, $punchCarbon);
            if ($classified !== null) {
                return $classified;
            }
        }

        // 2. Fallback: Chronological sequence of punches for this employee today
        $seqStatus = $this->classifyByDailySequence($bioId, $date, $punchCarbon);
        if ($seqStatus !== null) {
            return $seqStatus;
        }

        // 3. Ultimate Fallback: Time of day (Morning = 0 / In, Afternoon = 1 / Out)
        return ($punchCarbon->hour < 12) ? 0 : 1;
    }

    /**
     * Classify a punch using the employee's assigned UMIS time shift.
     *
     * @param TimeShifts $timeShift
     * @param Carbon $punchTime
     * @return int|null 0 (In), 1 (Out), or null if outside all shift windows
     */
    protected function classifyByTimeShift(TimeShifts $timeShift, Carbon $punchTime): ?int
    {
        $baseDate = $punchTime->copy()->startOfDay();

        // Build schedule slots: [ [type => 0|1, carbon => Carbon, name => string], ... ]
        $slots = [];

        // Check for 2-slot shifting vs 4-slot regular schedule
        $firstIn = $timeShift->first_in ? Carbon::parse($timeShift->first_in)->setDateFrom($baseDate)->startOfMinute() : null;
        $firstOut = $timeShift->first_out ? Carbon::parse($timeShift->first_out)->setDateFrom($baseDate)->startOfMinute() : null;
        $secondIn = $timeShift->second_in ? Carbon::parse($timeShift->second_in)->setDateFrom($baseDate)->startOfMinute() : null;
        $secondOut = $timeShift->second_out ? Carbon::parse($timeShift->second_out)->setDateFrom($baseDate)->startOfMinute() : null;

        // Handle cross-midnight shifts (e.g. 22:00 in -> 06:00 out)
        if ($firstIn && $firstOut && $firstOut->lt($firstIn)) {
            $firstOut->addDay();
        }

        if ($firstIn) {
            $slots[] = ['type' => 0, 'time' => $firstIn, 'slot' => 'first_in'];
        }
        if ($firstOut) {
            $slots[] = ['type' => 1, 'time' => $firstOut, 'slot' => 'first_out'];
        }
        if ($secondIn) {
            // If second_in is earlier than first_out, advance to next day
            if ($firstOut && $secondIn->lt($firstOut)) {
                $secondIn->addDay();
            }
            $slots[] = ['type' => 0, 'time' => $secondIn, 'slot' => 'second_in'];
        }
        if ($secondOut) {
            if ($secondIn && $secondOut->lt($secondIn)) {
                $secondOut->addDay();
            }
            $slots[] = ['type' => 1, 'time' => $secondOut, 'slot' => 'second_out'];
        }

        if (empty($slots)) {
            return null;
        }

        // Find the slot with the smallest time difference within the 3.5-hour window
        $bestDiff = null;
        $bestType = null;

        foreach ($slots as $slot) {
            $diff = abs($punchTime->diffInMinutes($slot['time']));
            if ($diff <= $this->windowMinutes) {
                if ($bestDiff === null || $diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestType = $slot['type'];
                }
            }
        }

        return $bestType;
    }

    /**
     * Classify based on the sequence of existing logs recorded today for this employee.
     *
     * @param int $bioId
     * @param string $date
     * @param Carbon $punchTime
     * @return int|null 0 for In, 1 for Out
     */
    protected function classifyByDailySequence(int $bioId, string $date, Carbon $punchTime): ?int
    {
        if (!Schema::hasTable('device_logs')) {
            return null;
        }

        try {
            // Count prior distinct punches today before this punch time
            $priorPunchesCount = DeviceLogs::where('biometric_id', $bioId)
                ->where('dtr_date', $date)
                ->where('date_time', '<', $punchTime->format('Y-m-d H:i:s'))
                ->count();

            // 0 prior punches today -> Check-In (0)
            if ($priorPunchesCount === 0) {
                return 0;
            }

            // Odd count of prior punches (e.g. 1 punch so far) -> Check-Out (1)
            // Even count of prior punches (e.g. 2 punches so far) -> Check-In (0)
            return ($priorPunchesCount % 2 === 1) ? 1 : 0;
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('classifyByDailySequence failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch active TimeShift for employee and date using UMIS relations.
     */
    protected function getEmployeeTimeShift(int $biometricId, string $date): ?TimeShifts
    {
        if (!Schema::hasTable('employee_profiles') || !Schema::hasTable('schedules')) {
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
