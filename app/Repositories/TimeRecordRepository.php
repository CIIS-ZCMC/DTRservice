<?php

namespace App\Repositories;

use App\Contracts\TimeRecordRepositoryInterface;
use App\Models\Biometrics;
use App\Models\DeviceLogs;
use App\Models\DTR;
use App\Models\Schedule;
use App\Models\TimeShifts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $profileIds = \App\Models\EmployeeProfile::where('biometric_id', $biometricId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        if (!empty($profileIds)) {
            return Schedule::where('date', $date)
                ->whereNull('deleted_at')
                ->whereHas('employeeSchedules', function ($query) use ($profileIds) {
                    $query->whereIn('employee_profile_id', $profileIds);
                })
                ->with('timeShift')
                ->orderBy('id', 'desc')
                ->first();
        }

        return null;
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
                return DTR::UpdateOrCreate([
                    'biometric_id' => $data['biometric_id'],
                    'dtr_date' => $data['dtr_date']
                ], $data);
            });
        } catch (\Exception $e) {
           Log::error('Error saving daily time record: ' . $e->getMessage());
        }

    }

    /**
     * Get all employees with device logs in date range
     */
    public function getEmployeesWithDeviceLogs(string $dateFrom, string $dateTo): array
    {
        return DeviceLogs::where('dtr_date', '>=', $dateFrom)
            ->where('dtr_date', '<=', $dateTo)
            ->distinct()
            ->pluck('biometric_id')
            ->toArray();
    }


}
