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
            ->with('employeeProfile', 'externalProfile')
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

        $employeeName = 'Unknown';
        $department = 'N/A';

        if ($employee->employeeProfile) {
            $employeeName = $employee->employeeProfile->personalInformation->employeeName() ?? 'Unknown';
            $department = $employee->employeeProfile->assignArea->area_name ?? 'N/A';
        } elseif ($employee->externalProfile) {
            $employeeName = trim(($employee->externalProfile->first_name ?? '') . ' ' . ($employee->externalProfile->last_name ?? '')) ?: 'Unknown';
            $department = $employee->externalProfile->department ?? 'N/A';
        }

        return [
            'employee' => [
                'biometric_id' => $biometricId,
                'name' => $employeeName,
                'department' => $department,
            ],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'records' => array_values($records),
        ];
    }

    /**
     * Get employee schedule for a specific date
     */
    private function getEmployeeSchedule(int $biometricId, string $date)
    {
        $employeeProfile = Biometrics::where('biometric_id', $biometricId)
            ->with('employeeProfile','externalProfile')
            ->first();

      
        if (!$employeeProfile || !$employeeProfile->employeeProfile) {
                if($employeeProfile->externalProfile){  
                return $employeeProfile->getSchedules($date);
               }
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
    private function getTimeShiftByDate(int $biometricId, string $date)
    {
        $schedule = $this->getEmployeeSchedule($biometricId, $date);

        if (!$schedule) {
            return null;
        }

        if ($schedule instanceof \App\Models\ExternalSchedule) {
            return null;
        }

        return $schedule->timeShift;
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

        $firstEntry = $timeShift->first_in ?? null;
        $lastEntry = $timeShift->second_out ?? null;
        $isCrossMidnight = $firstEntry !== null && $lastEntry !== null && $firstEntry > $lastEntry;

        return [
            'scheduleDate' => $schedule->date,
            'first_entry' => $firstEntry,
            'second_entry' => $timeShift->first_out ?? null,
            'third_entry' => $timeShift->second_in ?? null,
            'last_entry' => $lastEntry,
            'total_hours' => 8,
            'arrival_departure' => null,
            'is_cross_midnight' => $isCrossMidnight,
        ];
    }

    /**
     * Build schedule data array from ExternalSchedule model
     */
    private function buildExternalScheduleData(\App\Models\ExternalSchedule $schedule): array
    {
        $hasSecondIn = $schedule->second_in !== null && $schedule->second_in !== '';
        $hasSecondOut = $schedule->second_out !== null && $schedule->second_out !== '';

        if (!$hasSecondIn && !$hasSecondOut) {
            $isCrossMidnight = $schedule->first_in !== null
                && $schedule->first_out !== null
                && $schedule->first_in === $schedule->first_out;

            return [
                'scheduleDate' => $schedule->dtr_date,
                'first_entry' => $schedule->first_in ?? null,
                'second_entry' => null,
                'third_entry' => null,
                'last_entry' => $schedule->first_out ?? null,
                'total_hours' => 8,
                'arrival_departure' => null,
                'is_cross_midnight' => $isCrossMidnight,
            ];
        }

        $firstEntry = $schedule->first_in ?? null;
        $lastEntry = $schedule->second_out ?? null;
        $isCrossMidnight = $firstEntry !== null && $lastEntry !== null && $firstEntry > $lastEntry;

        return [
            'scheduleDate' => $schedule->dtr_date,
            'first_entry' => $firstEntry,
            'second_entry' => $schedule->first_out ?? null,
            'third_entry' => $schedule->second_in ?? null,
            'last_entry' => $lastEntry,
            'total_hours' => 8,
            'arrival_departure' => null,
            'is_cross_midnight' => $isCrossMidnight,
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

        // Detect PM schedule (first_entry >= 12:00)
        $isPmSchedule = $firstInTime !== null && $firstInTime >= '12:00:00';

        // Find closest log for each schedule slot (skip if schedule time is null)
        if ($isPmSchedule) {
            // PM schedule: first_in/first_out match AM logs only (for cross-midnight leftovers)
            // second_in matches against first_entry, second_out against last_entry
            $matched = [
                'first_in' => $this->findClosestLog($deviceLogs, $date . ' 08:00:00', '00:00:00', '12:00:00'),
                'first_out' => $this->findClosestLog($deviceLogs, $date . ' 12:00:00', '00:00:00', '13:00:00'),
                'second_in' => $firstInTime ? $this->findClosestLog($deviceLogs, $date . ' ' . $firstInTime) : null,
                'second_out' => ($secondOutTime && !$isCrossMidnight) ? $this->findClosestLog($deviceLogs, $date . ' ' . $secondOutTime) : null,
            ];
        } else {
            $matched = [
                'first_in' => $firstInTime ? $this->findClosestLog($deviceLogs, $date . ' ' . $firstInTime) : null,
                'first_out' => ($firstOutTime ?: '12:00:00') ? $this->findClosestLog($deviceLogs, $date . ' ' . ($firstOutTime ?: '12:00:00'), '00:00:00', '13:00:00') : null,
                'second_in' => $secondInTime ? $this->findClosestLog($deviceLogs, $date . ' ' . $secondInTime) : null,
                'second_out' => ($secondOutTime && !$isCrossMidnight) ? $this->findClosestLog($deviceLogs, $date . ' ' . $secondOutTime) : null,
            ];
        }

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
            if ($schedule instanceof \App\Models\ExternalSchedule) {
                $scheduleData = $this->buildExternalScheduleData($schedule);
            } elseif ($schedule && $timeShift) {
                $scheduleData = $this->buildScheduleData($schedule, $timeShift);
            }

            // Handle cross-midnight FIRST: if previous day was cross-midnight, find out time in current day's logs
            $crossMidnightLogDateTime = null;

            // Build previous day's schedule data
            $prevScheduleData = null;
            if ($i > 0) {
                $prevRecord = $records[$i - 1];
                $prevSchedule = $prevRecord['schedule'];
                $prevTimeShift = $prevRecord['time_shift'];

                if ($prevSchedule instanceof \App\Models\ExternalSchedule) {
                    $prevScheduleData = $this->buildExternalScheduleData($prevSchedule);
                } elseif ($prevSchedule && $prevTimeShift) {
                    $prevScheduleData = $this->buildScheduleData($prevSchedule, $prevTimeShift);
                }
            } else {
                // First record: fetch previous day's schedule directly (may be outside dateFrom range)
                $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
                $prevSchedule = $this->getEmployeeSchedule($biometricId, $prevDate);

                if ($prevSchedule instanceof \App\Models\ExternalSchedule) {
                    $prevScheduleData = $this->buildExternalScheduleData($prevSchedule);
                } elseif ($prevSchedule) {
                    $prevTimeShift = $prevSchedule->timeShift;
                    if ($prevTimeShift) {
                        $prevScheduleData = $this->buildScheduleData($prevSchedule, $prevTimeShift);
                    }
                }
            }

            if ($prevScheduleData) {

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

            // Attendance status logic
            $isWeekend = $dayName === 'Saturday' || $dayName === 'Sunday';
            $isFuture = $date > date('Y-m-d');
            $hasEntries = $timeSlots['first_in'] !== null || $timeSlots['second_in'] !== null || $timeSlots['second_out'] !== null;
            $hasScheduleData = $scheduleData !== null;
            $remarks = null;

            // Applications placeholder: if has leave/OT/OB, prioritize application (TODO: fill later)
            $hasApplications = !empty($dailyRecords) && false; // placeholder

            if (!$hasApplications) {
                if ($hasEntries && $hasScheduleData) {
                    // Show actual time — first_in already set from matching
                } elseif ($hasScheduleData && !$hasEntries) {
                    $timeSlots['first_in'] = 'ABSENT';
                } elseif (!$hasScheduleData && $hasEntries) {
                    $timeSlots['first_in'] = null;
                    $remarks = 'no schedule';
                } elseif (!$hasScheduleData && !$hasEntries && $isWeekend) {
                    $timeSlots['first_in'] = 'DAY OFF';
                } elseif (!$hasScheduleData && !$hasEntries && !$isWeekend && !$isFuture) {
                    $timeSlots['first_in'] = 'DAY OFF';
                } elseif ($isFuture && $isWeekend) {
                    $timeSlots['first_in'] = 'DAY OFF';
                } elseif ($isFuture && !$isWeekend) {
                    $timeSlots['first_in'] = null;
                }
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
                'remarks' => $remarks,
            ];
        }

        //dd("test",$dailyRecords[2],$dailyRecords[3]);
      //dd("test",$dailyRecords);

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
