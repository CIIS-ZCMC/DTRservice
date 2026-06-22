<?php

namespace App\Repositories;

use App\Contracts\TimeRecordRepositoryInterface;
use App\Models\Biometrics;
use App\Models\DeviceLogs;
use App\Models\DTR;
use App\Models\Schedule;
use App\Models\TimeShifts;
use Illuminate\Support\Facades\DB;

class TimeRecordRepository implements TimeRecordRepositoryInterface
{
    /**
     * Get device logs by biometric ID
     */
    public function getDeviceLogsByBiometricId(int $biometricId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = DeviceLogs::where('biometric_id', $biometricId);

        if ($dateFrom) {
            $query->where('dtr_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('dtr_date', '<=', $dateTo);
        }

        return $query->orderBy('date_time')->get()->toArray();
    }

    /**
     * Get employee schedule for a specific date
     */
    public function getEmployeeSchedule(int $biometricId, string $date): ?Schedule
    {
        $employeeProfile = Biometrics::where('biometric_id', $biometricId)
            ->with('employeeProfile')
            ->first();

        if (!$employeeProfile || !$employeeProfile->employeeProfile) {
            return null;
        }

        return Schedule::where('date', $date)
            ->whereHas('employeeSchedules', function ($query) use ($employeeProfile) {
                $query->where('employee_profile_id', $employeeProfile->employeeProfile->id);
            })
            ->with('timeShift')
            ->first();
    }

    /**
     * Get time shift for a specific date
     */
    public function getTimeShiftByDate(int $biometricId, string $date): ?TimeShifts
    {
        $schedule = $this->getEmployeeSchedule($biometricId, $date);

        return $schedule ? $schedule->timeShift : null;
    }

    /**
     * Save daily time record
     */
    public function saveDailyTimeRecord(array $data)
    {
        try {
            return DB::transaction(function () use ($data) {
                return DTR::create($data);
            });
        } catch (\Exception $e) {
            // Handle error
            throw $e;
        }

    }


}
