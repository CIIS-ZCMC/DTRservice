<?php

namespace App\Repositories;

use App\Contracts\ScheduleRepositoryInterface;
use App\Models\Biometrics;
use App\Models\ExternalEmployee;
use App\Models\ExternalSchedule;
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

        $schedule = DB::select("
            SELECT s.*,
            CASE
                WHEN s.id IS NOT NULL THEN
                    (SELECT date
                    FROM schedules
                    WHERE date = :date1
                    AND deleted_at IS NULL
                    AND time_shift_id = s.id
                    LIMIT 1)
                ELSE 'NONE'
            END AS date
            FROM time_shifts s
            WHERE s.deleted_at IS NULL
            AND s.id IN (
                SELECT time_shift_id
                FROM schedules
                WHERE date = :date2
                AND deleted_at IS NULL
                AND id IN (
                    SELECT schedule_id
                    FROM employee_profile_schedule
                    WHERE employee_profile_id IN (
                        SELECT id
                        FROM employee_profiles
                        WHERE biometric_id = :biometric_id
                        AND deleted_at IS NULL
                    )
                )
            )
        ", [
            'date1' => $date,
            'date2' => $date,
            'biometric_id' => $biometricId,
        ]);

        if (!empty($schedule)) {
            return $schedule;
        }

        // Fallback for external employee
        $externalEmployee = ExternalEmployee::where('biometric_id', $biometricId)->first();
        if ($externalEmployee) {
            $extSched = ExternalSchedule::where('external_employee_id', $externalEmployee->id)
                ->where('dtr_date', $date)
                ->first();

            if ($extSched) {
                return [(object) [
                    'id' => $extSched->id,
                    'first_in' => $extSched->first_in,
                    'first_out' => $extSched->first_out,
                    'second_in' => $extSched->second_in,
                    'second_out' => $extSched->second_out,
                    'total_hours' => 8,
                    'date' => $extSched->dtr_date,
                ]];
            }
        }

        return $schedule;
    }
}

