<?php

namespace App\Repositories;

use App\Contracts\ScheduleRepositoryInterface;
use App\Models\Biometrics;
use App\Models\ExternalEmployees;
use Illuminate\Support\Facades\DB;

class ScheduleRepository implements ScheduleRepositoryInterface
{
    /**
     * Get schedule by employee ID
     */
    public function getScheduleByDate(int $biometricId, string $date): ?array
    {
        $default = [
            'first_in' => config('app.default_first_in'),
            'first_out' => config('app.default_first_out'),
            'last_in' => config('app.default_last_in'),
            'last_out' => config('app.default_last_out'),
        ];

         $Schedule = DB::select("
                SELECT s.*,
                CASE
                    WHEN s.id IS NOT NULL THEN
                        (SELECT date
                        FROM schedules
                        WHERE '$date' = date
                        AND status = 1
                        AND time_shift_id = s.id
                        LIMIT 1)
                    ELSE 'NONE'
                END AS date
                FROM time_shifts s
                WHERE s.id IN (
                SELECT time_shift_id
                FROM schedules
                WHERE '$date' = date
                AND status = 1
                AND id IN (
                SELECT schedule_id
                FROM employee_profile_schedule
                WHERE employee_profile_id IN (
                    SELECT id
                    FROM employee_profiles
                    WHERE biometric_id = '$biometricId'
                )
                )
                );

        ");


       
        return $Schedule;
    }

}
