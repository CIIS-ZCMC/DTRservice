<?php

namespace App\Repositories;

use App\Contracts\DtrReportRepositoryInterface;
use App\Models\Biometrics;
use App\Models\DeviceLogs;
use Illuminate\Support\Facades\DB;

class DtrReportRepository implements DtrReportRepositoryInterface
{
    /**
     * Get DTR data for an employee within a date range
     */
    public function getEmployeeDtrData(int $biometricId, string $dateFrom, string $dateTo): array
    {
        $employee = Biometrics::where('biometric_id', $biometricId)
            ->with('employeeProfile')
            ->first();

        if (!$employee) {
            return [];
        }

        $logs = DeviceLogs::where('biometric_id', $biometricId)
            ->whereBetween('dtr_date', [$dateFrom, $dateTo])
            ->orderBy('dtr_date')
            ->orderBy('date_time')
            ->get()
            ->toArray();

        // Group logs by date and include schedule/time_shift
        $records = [];
        foreach ($logs as $log) {
            $date = $log['dtr_date'];
            if (!isset($records[$date])) {
                $records[$date] = [
                    'date' => $date,
                    'device_logs' => [],
                    'schedule' => $this->getEmployeeSchedule($biometricId, $date),
                    'time_shift' => $this->getTimeShiftByDate($biometricId, $date),
                ];
            }
            $records[$date]['device_logs'][] = $log;
        }
     
        return [
            'employee' => [
                'biometric_id' => $biometricId,
                'name' => $employee->employeeProfile->personalInformation->employeeName() ?? 'Unknown',
                'department' => $employee->employeeProfile->assignArea->area_name ?? 'N/A',
            ],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'records' => array_values($records),
        ];
    }

    /**
     * Get employee schedule for a specific date
     */
    private function getEmployeeSchedule(int $biometricId, string $date): ?\App\Models\Schedule
    {
        $employeeProfile = Biometrics::where('biometric_id', $biometricId)
            ->with('employeeProfile')
            ->first();

      

        if (!$employeeProfile || !$employeeProfile->employeeProfile) {
            return null;
        }

     

        return \App\Models\Schedule::where('date', $date)
            ->whereHas('employeeSchedules', function ($query) use ($employeeProfile) {
                $query->where('employee_profile_id', $employeeProfile->employeeProfile->id);
            })
            ->with('timeShift')
            ->first();
    }

    /**
     * Get time shift for a specific date
     */
    private function getTimeShiftByDate(int $biometricId, string $date): ?\App\Models\TimeShifts
    {
        $schedule = $this->getEmployeeSchedule($biometricId, $date);

        return $schedule ? $schedule->timeShift : null;
    }

    /**
     * Build schedule data array, rearranging slots if no second_in/second_out
     */
    private function buildScheduleData(\App\Models\Schedule $schedule, \App\Models\TimeShifts $timeShift): array
    {
        $hasSecondIn = $timeShift->second_in !== null && $timeShift->second_in !== '';
        $hasSecondOut = $timeShift->second_out !== null && $timeShift->second_out !== '';

        if (!$hasSecondIn && !$hasSecondOut) {
            $isCrossMidnight = $timeShift->first_in !== null
                && $timeShift->first_out !== null
                && $timeShift->first_in === $timeShift->first_out;

            return [
                'scheduleDate' => $schedule->date,
                'first_entry' => $timeShift->first_in ?? null,
                'second_entry' => null,
                'third_entry' => null,
                'last_entry' => $timeShift->first_out ?? null,
                'total_hours' => 8,
                'arrival_departure' => null,
                'is_cross_midnight' => $isCrossMidnight,
            ];
        }

        return [
            'scheduleDate' => $schedule->date,
            'first_entry' => $timeShift->first_in ?? null,
            'second_entry' => $timeShift->first_out ?? null,
            'third_entry' => $timeShift->second_in ?? null,
            'last_entry' => $timeShift->second_out ?? null,
            'total_hours' => 8,
            'arrival_departure' => null,
            'is_cross_midnight' => false,
        ];
    }

    /**
     * Format time from 24-hour to 12-hour AM/PM format
     */
    private function formatTime(?string $time): ?string
    {
        if (!$time) {
            return null;
        }

        return date('h:i A', strtotime($time));
    }

    /**
     * Match device logs to schedule slots based on nearest time proximity
     */
    private function matchLogsToScheduleSlots(array $deviceLogs, array $schedule, string $date): array
    {
        if (empty($deviceLogs) || empty($schedule)) {
            return [
                'first_in' => null,
                'first_out' => null,
                'second_in' => null,
                'second_out' => null,
            ];
        }

        $isCrossMidnight = !empty($schedule['is_cross_midnight']);

        // Parse schedule times into full datetime strings (skip null entries)
        $firstInTime = $schedule['first_entry'] ?? null;
        $firstOutTime = $schedule['second_entry'] ?? null;
        $secondInTime = $schedule['third_entry'] ?? null;
        $secondOutTime = $schedule['last_entry'] ?? null;

        // Find closest log for each schedule slot (skip if schedule time is null)
        $matched = [
            'first_in' => $firstInTime ? $this->findClosestLog($deviceLogs, $date . ' ' . $firstInTime) : null,
            'first_out' => ($firstOutTime ?: '12:00:00') ? $this->findClosestLog($deviceLogs, $date . ' ' . ($firstOutTime ?: '12:00:00'), '00:00:00', '13:00:00') : null,
            'second_in' => $secondInTime ? $this->findClosestLog($deviceLogs, $date . ' ' . $secondInTime) : null,
            'second_out' => ($secondOutTime && !$isCrossMidnight) ? $this->findClosestLog($deviceLogs, $date . ' ' . $secondOutTime) : null,
        ];

        // Format times to 12-hour format
        return [
            'first_in' => $matched['first_in'] ? $this->formatTime(substr($matched['first_in'], 11, 8)) : null,
            'first_out' => $matched['first_out'] ? $this->formatTime(substr($matched['first_out'], 11, 8)) : null,
            'second_in' => $matched['second_in'] ? $this->formatTime(substr($matched['second_in'], 11, 8)) : null,
            'second_out' => $matched['second_out'] ? $this->formatTime(substr($matched['second_out'], 11, 8)) : null,
        ];
    }

    /**
     * Find the device log closest to a target datetime (within 3 hours)
     * Optional time range constraint (e.g., '00:00:00' to '13:00:00')
     */
    private function findClosestLog(array $deviceLogs, string $targetDateTime, ?string $minTime = null, ?string $maxTime = null, int $maxThreshold = 10800): ?string
    {
        $targetTimestamp = strtotime($targetDateTime);
        $closestLog = null;
        $minDiff = PHP_INT_MAX;

        foreach ($deviceLogs as $log) {
            $logTime = substr($log['date_time'], 11, 8);

            if ($minTime !== null && $logTime < $minTime) {
                continue;
            }
            if ($maxTime !== null && $logTime > $maxTime) {
                continue;
            }

            $logTimestamp = strtotime($log['date_time']);
            $diff = abs($logTimestamp - $targetTimestamp);

            if ($diff <= $maxThreshold && $diff < $minDiff) {
                $minDiff = $diff;
                $closestLog = $log['date_time'];
            }
        }

        return $closestLog;
    }

    /**
     * Generate report data with calculated totals
     */
    public function generateReport(int $biometricId, string $dateFrom, string $dateTo): array
    {
        $data = $this->getEmployeeDtrData($biometricId, $dateFrom, $dateTo);

        if (empty($data)) {
            return [];
        }

    // Process daily records with schedule info
        $dailyRecords = [];
        $records = $data['records'];
        $recordCount = count($records);

        for ($i = 0; $i < $recordCount; $i++) {
            $record = $records[$i];
            $deviceLogs = $record['device_logs'];
            $schedule = $record['schedule'];
            $timeShift = $record['time_shift'];

            $date = $record['date'];
            $dateObj = date_create($date);
            $dayNum = (int)$dateObj->format('j');
            $dayName = $dateObj->format('l');
            $dayShort = $dateObj->format('D');

            // Match logs to schedule slots
            $timeSlots = [
                'first_in' => null,
                'first_out' => null,
                'second_in' => null,
                'second_out' => null,
            ];

            $scheduleData = null;
            if ($schedule && $timeShift) {
                $scheduleData = $this->buildScheduleData($schedule, $timeShift);
            }

            // Handle cross-midnight FIRST: if previous day was cross-midnight, find out time in current day's logs
            $crossMidnightLogDateTime = null;
            if ($i > 0) {
                $prevRecord = $records[$i - 1];
                $prevSchedule = $prevRecord['schedule'];
                $prevTimeShift = $prevRecord['time_shift'];

                if ($prevSchedule && $prevTimeShift) {
                    $prevScheduleData = $this->buildScheduleData($prevSchedule, $prevTimeShift);

                    if (!empty($prevScheduleData['is_cross_midnight']) && !empty($deviceLogs)) {
                        $prevLastEntry = $prevScheduleData['last_entry'] ?? '08:00:00';
                        $targetDateTime = $date . ' ' . $prevLastEntry;

                        // Dynamic search window based on current day's schedule
                        $maxSearchTime = null;
                        $searchThreshold = 21600; // 6h default

                        if ($scheduleData && !empty($scheduleData['first_entry'])) {
                            $currentIsCrossMidnight = !empty($scheduleData['is_cross_midnight']);
                            if (!$currentIsCrossMidnight) {
                                // Current day has a normal schedule: don't steal its first_in log
                                $maxSearchTime = $scheduleData['first_entry'];
                                $searchThreshold = max(3600, strtotime($scheduleData['first_entry']) - strtotime($prevLastEntry));
                            }
                        }

                        $crossMidnightLogDateTime = $this->findClosestLog($deviceLogs, $targetDateTime, $prevLastEntry, $maxSearchTime, $searchThreshold);

                        if ($crossMidnightLogDateTime) {
                            $logTime = substr($crossMidnightLogDateTime, 11, 8);
                            $formattedTime = $this->formatTime($logTime);

                            // Dynamic AM/PM threshold from current day's schedule
                            $amPmThreshold = '13:00:00';
                            if ($scheduleData) {
                                if (!empty($scheduleData['second_entry'])) {
                                    $amPmThreshold = $scheduleData['second_entry'];
                                } elseif (!empty($scheduleData['first_entry'])
                                    && !empty($scheduleData['last_entry'])
                                    && $scheduleData['first_entry'] !== $scheduleData['last_entry']) {
                                    $firstTs = strtotime($scheduleData['first_entry']);
                                    $lastTs = strtotime($scheduleData['last_entry']);
                                    $midTs = (int)(($firstTs + $lastTs) / 2);
                                    $amPmThreshold = date('H:i:s', $midTs);
                                }
                            }

                            // AM log -> first_out; PM log -> second_out
                            if ($logTime < $amPmThreshold) {
                                $timeSlots['first_out'] = $formattedTime;
                            } else {
                                $timeSlots['second_out'] = $formattedTime;
                            }
                        }
                    }
                }
            }

            // Remove cross-midnight log from device logs so it's not reused by matchLogsToScheduleSlots
            $logsForMatching = $deviceLogs;
            if ($crossMidnightLogDateTime !== null) {
                $logsForMatching = array_filter($deviceLogs, function ($log) use ($crossMidnightLogDateTime) {
                    return $log['date_time'] !== $crossMidnightLogDateTime;
                });
            }

            if (!empty($logsForMatching) && $scheduleData) {
                $matchedSlots = $this->matchLogsToScheduleSlots($logsForMatching, $scheduleData, $date);
                // Merge: only fill slots that are still null (don't overwrite cross-midnight values)
                foreach ($matchedSlots as $key => $value) {
                    if ($timeSlots[$key] === null && $value !== null) {
                        $timeSlots[$key] = $value;
                    }
                }
            }

            // Format schedule
            $hasSchedule = [];
            if ($scheduleData) {
                $hasSchedule[] = $scheduleData;
            }

            $dailyRecords[] = [
                'dtr_date' => $date,
                'day' => $dayNum,
                'day_name' => $dayName,
                'day_short' => $dayShort,
                'first_in' => $timeSlots['first_in'],
                'first_out' => $timeSlots['first_out'],
                'second_in' => $timeSlots['second_in'],
                'second_out' => $timeSlots['second_out'],
                'has_leave' => [],
                'has_ob' => [],
                'has_ot' => [],
                'has_cto' => [],
                'has_ta' => null,
                'has_schedule' => $hasSchedule,
                'has_undertime' => [],
                'has_holiday' => [],
                'undertime' => null,
                'attendance_status' => 1,
            ];
        }

        //dd("test",$dailyRecords[2],$dailyRecords[3]);
       ///dd("test",$dailyRecords);

        // Calculate totals
        $totalDays = count($dailyRecords);

        return [
            'employee' => $data['employee'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'daily_records' => $dailyRecords,
            'summary' => [
                'total_days' => $totalDays,
            ],
        ];
    }
}
