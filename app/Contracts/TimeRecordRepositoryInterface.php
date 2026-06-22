<?php

namespace App\Contracts;

interface TimeRecordRepositoryInterface
{
    /**
     * Get device logs by biometric ID
     */
    public function getDeviceLogsByBiometricId(int $biometricId, ?string $dateFrom = null, ?string $dateTo = null): array;

    /**
     * Get employee schedule for a specific date
     */
    public function getEmployeeSchedule(int $biometricId, string $date): ?\App\Models\Schedule;

    /**
     * Get time shift for a specific date
     */
    public function getTimeShiftByDate(int $biometricId, string $date): ?\App\Models\TimeShifts;
    
    /**
     * Save daily time record
     */
    public function saveDailyTimeRecord(array $data);
}
