<?php

namespace App\Repositories;

use App\Contracts\DtrReportRepositoryInterface;
use App\Models\Biometrics;
use App\Models\DeviceLogs;
use App\Models\LeaveApplication;
use App\Models\OfficialBusinessApplication;
use App\Models\OfficialTimeApplication;
use App\Models\CtoApplication;
use App\Models\TimeAdjustment;
use App\Models\Holiday;
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

        // Fill missing dates in the range
        $period = new \DatePeriod(
            new \DateTime($dateFrom),
            new \DateInterval('P1D'),
            (new \DateTime($dateTo))->modify('+1 day')
        );

        foreach ($period as $dt) {
            $date = $dt->format('Y-m-d');
            if (!isset($records[$date])) {
                $records[$date] = [
                    'date' => $date,
                    'device_logs' => [],
                    'schedule' => $this->getEmployeeSchedule($biometricId, $date),
                    'time_shift' => $this->getTimeShiftByDate($biometricId, $date),
                ];
            }
        }

        ksort($records);

        $employeeName = 'Unknown';
        $department = 'N/A';

        if ($employee->employeeProfile) {
            $employeeName = $employee->employeeProfile->personalInformation->employeeName() ?? 'Unknown';
            $department = $employee->employeeProfile->assignArea->area_name ?? 'N/A';
        } elseif ($employee->externalProfile) {
            $employeeName = trim(($employee->externalProfile->first_name ?? '') . ' ' . ($employee->externalProfile->last_name ?? '')) ?: 'Unknown';
            $department = $employee->externalProfile->department ?? 'N/A';
        }

        $employeeProfileId = $employee->employeeProfile?->id;

        return [
            'employee' => [
                'biometric_id' => $biometricId,
                'name' => $employeeName,
                'department' => $department,
                'employee_profile_id' => $employeeProfileId,
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
     * Get approved applications for an internal employee on a specific date
     */
    private function getApplications(?int $employeeProfileId, string $date): array
    {
        $empty = [
            'has_leave' => [],
            'has_ob' => [],
            'has_ot' => [],
            'has_cto' => [],
            'has_ta' => null,
        ];

        if (!$employeeProfileId) {
            return $empty;
        }

        $leaveApps = LeaveApplication::where('employee_profile_id', $employeeProfileId)
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $date)
            ->whereDate('date_to', '>=', $date)
            ->get()
            ->toArray();

        $obApps = OfficialBusinessApplication::where('employee_profile_id', $employeeProfileId)
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $date)
            ->whereDate('date_to', '>=', $date)
            ->get()
            ->toArray();

        $otApps = OfficialTimeApplication::where('employee_profile_id', $employeeProfileId)
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $date)
            ->whereDate('date_to', '>=', $date)
            ->get()
            ->toArray();

        $ctoApps = CtoApplication::where('employee_profile_id', $employeeProfileId)
            ->where('status', 'approved')
            ->whereDate('date', $date)
            ->get()
            ->toArray();

        $timeAdjustment = TimeAdjustment::where('employee_profile_id', $employeeProfileId)
            ->where('status', 'approved')
            ->whereDate('date', $date)
            ->first();

        return [
            'has_leave' => $leaveApps,
            'has_ob' => $obApps,
            'has_ot' => $otApps,
            'has_cto' => $ctoApps,
            'has_ta' => $timeAdjustment ? $timeAdjustment->toArray() : null,
        ];
    }

    /**
     * Get holiday for a specific date (matches by month-day)
     */
    private function getHoliday(string $date): ?array
    {
        $monthDay = substr($date, 5, 5); // Extract MM-DD from Y-m-d

        $holiday = Holiday::where('month_day', $monthDay)->first();

        return $holiday ? ['description' => $holiday->description] : null;
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
                'first_in' => $isCrossMidnight ? $this->findClosestLog($deviceLogs, $date . ' 08:00:00', '00:00:00', '12:00:00') : null,
                'first_out' => $isCrossMidnight ? $this->findClosestLog($deviceLogs, $date . ' 12:00:00', '00:00:00', '13:00:00') : null,
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
        $employeeProfileId = $data['employee']['employee_profile_id'] ?? null;

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

            // If no schedule but has device logs, create regulated entries using 12 PM split
            $regulatedEntries = null;
            if (!$scheduleData && !empty($deviceLogs)) {
                $amLogs = [];
                $pmLogs = [];
                foreach ($deviceLogs as $log) {
                    $logTime = substr($log['date_time'], 11, 8);
                    $formattedTime = $this->formatTime($logTime);
                    if ($logTime < '12:00:00') {
                        $amLogs[] = $formattedTime;
                    } else {
                        $pmLogs[] = $formattedTime;
                    }
                }
                sort($amLogs);
                sort($pmLogs);
                $regulatedEntries = [
                    'first_in' => count($amLogs) >= 1 ? $amLogs[0] : null,
                    'first_out' => count($amLogs) >= 2 ? $amLogs[1] : null,
                    'second_in' => count($pmLogs) >= 1 ? $pmLogs[0] : null,
                    'second_out' => count($pmLogs) >= 2 ? $pmLogs[1] : null,
                ];
            }

            // Format schedule
            $hasSchedule = [];
            if ($scheduleData) {
                $hasSchedule[] = $scheduleData;
            }

            // Fetch applications (internal employees only)
            $applications = $this->getApplications($employeeProfileId, $date);

            // Fetch holiday
            $holiday = $this->getHoliday($date);

            // Attendance status logic
            $isWeekend = $dayName === 'Saturday' || $dayName === 'Sunday';
            $isFuture = $date > date('Y-m-d');
            $hasEntries = $timeSlots['first_in'] !== null || $timeSlots['first_out'] !== null || $timeSlots['second_in'] !== null || $timeSlots['second_out'] !== null;
            $hasScheduleData = $scheduleData !== null;
            $remarks = null;

            // TA: if approved time adjustment exists, override time slots with TA values
            if ($applications['has_ta']) {
                $ta = $applications['has_ta'];
                if (!empty($ta['first_in'])) {
                    $timeSlots['first_in'] = $this->formatTime($ta['first_in']);
                }
                if (!empty($ta['first_out'])) {
                    $timeSlots['first_out'] = $this->formatTime($ta['first_out']);
                }
                if (!empty($ta['second_in'])) {
                    $timeSlots['second_in'] = $this->formatTime($ta['second_in']);
                }
                if (!empty($ta['second_out'])) {
                    $timeSlots['second_out'] = $this->formatTime($ta['second_out']);
                }
            } elseif (!empty($applications['has_leave'])) {
                $timeSlots['first_in'] = 'LV';
                $timeSlots['first_out'] = null;
                $timeSlots['second_in'] = null;
                $timeSlots['second_out'] = null;
                $remarks = 'Leave';
            } elseif (!empty($applications['has_ob'])) {
                $timeSlots['first_in'] = 'OB';
                $timeSlots['first_out'] = null;
                $timeSlots['second_in'] = null;
                $timeSlots['second_out'] = null;
                $remarks = 'Official Business';
            } elseif (!empty($applications['has_ot'])) {
                $timeSlots['first_in'] = 'OT';
                $timeSlots['first_out'] = null;
                $timeSlots['second_in'] = null;
                $timeSlots['second_out'] = null;
                $remarks = 'Official Time';
            } elseif (!empty($applications['has_cto'])) {
                $timeSlots['first_in'] = 'CTO';
                $timeSlots['first_out'] = null;
                $timeSlots['second_in'] = null;
                $timeSlots['second_out'] = null;
                $remarks = 'CTO';
            } else {
                if ($hasEntries && $hasScheduleData) {
                    // Show actual time — first_in already set from matching
                } elseif ($hasScheduleData && !$hasEntries) {
                    $timeSlots['first_in'] = 'ABSENT';
                } elseif (!$hasScheduleData && $holiday) {
                    if ($hasEntries) {
                        $timeSlots['second_in'] = $holiday['description'];
                        $remarks = $holiday['description'];
                    } else {
                        $timeSlots['first_in'] = $holiday['description'];
                        $timeSlots['first_out'] = null;
                        $timeSlots['second_in'] = null;
                        $timeSlots['second_out'] = null;
                        $remarks = $holiday['description'];
                    }
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

            // Calculate undertime
            $undertime = $this->calculateUndertime($timeSlots, $scheduleData);

            $dailyRecords[] = [
                'dtr_date' => $date,
                'day' => $dayNum,
                'day_name' => $dayName,
                'day_short' => $dayShort,
                'first_in' => $timeSlots['first_in'],
                'first_out' => $timeSlots['first_out'],
                'second_in' => $timeSlots['second_in'],
                'second_out' => $timeSlots['second_out'],
                'has_leave' => $applications['has_leave'],
                'has_ob' => $applications['has_ob'],
                'has_ot' => $applications['has_ot'],
                'has_cto' => $applications['has_cto'],
                'has_ta' => $applications['has_ta'],
                'has_schedule' => $hasSchedule,
                'has_undertime' => [],
                'has_holiday' => $holiday ? [$holiday] : [],
                'undertime' => $undertime,
                'undertime_in_words' => $this->minutesToWords($undertime),
                'attendance_status' => 1,
                'remarks' => $remarks,
                'regulated_entries' => $regulatedEntries ?? null,
                'data'=>$deviceLogs
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
            'arrival_departure' => $this->buildArrivalDeparture($dailyRecords),
            'hours' => $this->buildScheduleHours($dailyRecords),
            'daily_records' => $dailyRecords,
            'summary' => [
                'total_days' => $totalDays,
            ],
        ];
    }

    /**
     * Format a time string (H:i:s) to compact AM/PM format (e.g., 8AM, 1PM)
     */
    private function formatTimeForArrival(?string $time): ?string
    {
        if (!$time) {
            return null;
        }
        $timestamp = strtotime($time);
        if ($timestamp === false) {
            return null;
        }
        return date('gA', $timestamp);
    }

    /**
     * Build arrival/departure string from unique schedules across all daily records
     */
    private function buildArrivalDeparture(array $dailyRecords): ?string
    {
        $uniqueSchedules = [];
        $seen = [];

        foreach ($dailyRecords as $record) {
            if (empty($record['has_schedule'])) {
                continue;
            }
            foreach ($record['has_schedule'] as $schedule) {
                $first = $schedule['first_entry'] ?? null;
                $second = $schedule['second_entry'] ?? null;
                $third = $schedule['third_entry'] ?? null;
                $last = $schedule['last_entry'] ?? null;

                $key = "{$first}/{$second}/{$third}/{$last}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $parts = [];
                if ($first && $second) {
                    $parts[] = $this->formatTimeForArrival($first) . '/' . $this->formatTimeForArrival($second);
                }
                if ($third && $last) {
                    $parts[] = $this->formatTimeForArrival($third) . '/' . $this->formatTimeForArrival($last);
                }
                if (empty($parts) && $first && $last) {
                    $parts[] = $this->formatTimeForArrival($first) . '/' . $this->formatTimeForArrival($last);
                }

                if (!empty($parts)) {
                    $uniqueSchedules[] = implode(' ', $parts);
                }
            }
        }

        if (empty($uniqueSchedules)) {
            return null;
        }

        return implode(' | ', $uniqueSchedules);
    }

    /**
     * Build schedule hours string from unique schedules across all daily records
     */
    private function buildScheduleHours(array $dailyRecords): ?string
    {
        $uniqueHours = [];
        $seen = [];

        foreach ($dailyRecords as $record) {
            if (empty($record['has_schedule'])) {
                continue;
            }
            foreach ($record['has_schedule'] as $schedule) {
                $first = $schedule['first_entry'] ?? null;
                $second = $schedule['second_entry'] ?? null;
                $third = $schedule['third_entry'] ?? null;
                $last = $schedule['last_entry'] ?? null;
                $isCrossMidnight = $schedule['is_cross_midnight'] ?? false;

                // 24h pattern: first_in === first_out
                if ($first !== null && $last !== null && $first === $last && !$second && !$third) {
                    $hours = 24;
                } elseif ($first && $second && $third && $last) {
                    // Split shift: (second - first) + (last - third)
                    $hours = $this->calculateHourDiff($first, $second) + $this->calculateHourDiff($third, $last);
                } elseif ($first && $last) {
                    // Single pair
                    if ($isCrossMidnight || $first > $last) {
                        // Cross-midnight: (24 - first) + last
                        $hours = $this->calculateHourDiff($first, '24:00:00') + $this->calculateHourDiff('00:00:00', $last);
                    } else {
                        $hours = $this->calculateHourDiff($first, $last);
                    }
                } else {
                    continue;
                }

                $hours = (int) round($hours);
                if (isset($seen[$hours])) {
                    continue;
                }
                $seen[$hours] = true;
                $uniqueHours[] = "{$hours} Hours";
            }
        }

        if (empty($uniqueHours)) {
            return null;
        }

        return implode(' | ', $uniqueHours);
    }

    /**
     * Calculate hour difference between two time strings
     */
    private function calculateHourDiff(string $from, string $to): float
    {
        $fromTs = strtotime($from);
        $toTs = strtotime($to);
        return ($toTs - $fromTs) / 3600;
    }

    /**
     * Calculate undertime in minutes by comparing actual log times vs schedule times
     */
    private function calculateUndertime(array $timeSlots, ?array $scheduleData): ?int
    {
        if (!$scheduleData) {
            return null;
        }

        $first = $scheduleData['first_entry'] ?? null;
        $second = $scheduleData['second_entry'] ?? null;
        $third = $scheduleData['third_entry'] ?? null;
        $last = $scheduleData['last_entry'] ?? null;

        if (!$first) {
            return null;
        }

        $totalMinutes = 0;

        // Convert time slots from 'h:i A' format to minutes since midnight
        $toMinutes = function (?string $time): ?int {
            if (!$time) {
                return null;
            }
            $ts = strtotime($time);
            if ($ts === false) {
                return null;
            }
            return (int)date('H', $ts) * 60 + (int)date('i', $ts);
        };

        // Convert schedule time from 'H:i:s' format to minutes since midnight
        $toMinutesFrom24 = function (?string $time): ?int {
            if (!$time) {
                return null;
            }
            $parts = explode(':', $time);
            return (int)$parts[0] * 60 + (int)$parts[1];
        };

        $schedFirst = $toMinutesFrom24($first);
        $schedSecond = $toMinutesFrom24($second);
        $schedThird = $toMinutesFrom24($third);
        $schedLast = $toMinutesFrom24($last);

        $actualFirstIn = $toMinutes($timeSlots['first_in']);
        $actualFirstOut = $toMinutes($timeSlots['first_out']);
        $actualSecondIn = $toMinutes($timeSlots['second_in']);
        $actualSecondOut = $toMinutes($timeSlots['second_out']);

        // Skip undertime calculation for non-time values (LV, OB, OT, CTO, ABSENT, DAY OFF, holidays)
        $isNonTimeValue = function ($val) use ($toMinutes) {
            return $val !== null && $toMinutes($val) === null;
        };
        if ($isNonTimeValue($timeSlots['first_in']) || $isNonTimeValue($timeSlots['first_out'])) {
            return null;
        }

        if ($second && $third) {
            // Split shift (e.g., 8AM-12PM / 1PM-5PM)
            // AM shift: first_in → first_out vs schedFirst → schedSecond
            if ($actualFirstIn === null && $actualFirstOut === null) {
                // Both AM punches missing → entire AM shift is undertime
                $totalMinutes += $schedSecond - $schedFirst;
            } elseif ($actualFirstIn === null) {
                // Missing first_in → count full AM shift as undertime
                $totalMinutes += $schedSecond - $schedFirst;
            } else {
                // Late arrival
                if ($actualFirstIn > $schedFirst) {
                    $totalMinutes += $actualFirstIn - $schedFirst;
                }
                // Early out (AM)
                if ($actualFirstOut !== null && $actualFirstOut < $schedSecond) {
                    $totalMinutes += $schedSecond - $actualFirstOut;
                }
            }

            // PM shift: second_in → second_out vs schedThird → schedLast
            if ($actualSecondIn === null && $actualSecondOut === null) {
                // Both PM punches missing → entire PM shift is undertime
                $totalMinutes += $schedLast - $schedThird;
            } elseif ($actualSecondIn === null) {
                // Missing second_in → count full PM shift as undertime
                $totalMinutes += $schedLast - $schedThird;
            } else {
                // Late in (PM)
                if ($actualSecondIn > $schedThird) {
                    $totalMinutes += $actualSecondIn - $schedThird;
                }
                // Early out (PM)
                if ($actualSecondOut !== null && $actualSecondOut < $schedLast) {
                    $totalMinutes += $schedLast - $actualSecondOut;
                }
            }
        } else {
            // Single pair schedule - only calculate early out, skip missing punch undertime
            $schedLastTime = $schedLast ?? $schedSecond;
            $actualOut = $actualSecondOut ?? $actualFirstOut;
            if ($actualOut !== null && $schedLastTime !== null && $actualOut < $schedLastTime) {
                // Handle cross-midnight: if schedule end is next day (schedLast < schedFirst)
                if ($schedLastTime < $schedFirst) {
                    $schedLastTime += 24 * 60;
                }
                if ($actualOut < $schedFirst) {
                    $actualOut += 24 * 60;
                }
                $totalMinutes += $schedLastTime - $actualOut;
            }
        }

        return $totalMinutes > 0 ? $totalMinutes : null;
    }

    /**
     * Convert minutes to human-readable words (e.g., '1 hour 30 minutes')
     */
    private function minutesToWords(?int $minutes): ?string
    {
        if (!$minutes || $minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        if ($mins > 0) {
            $parts[] = $mins . ' minute' . ($mins > 1 ? 's' : '');
        }

        return implode(' ', $parts);
    }
}
