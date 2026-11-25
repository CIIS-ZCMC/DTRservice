<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceInformation;
use App\Models\Biometrics;
use App\Models\DeviceLogs;
use App\Models\DeviceLogsHrbliz;
use App\Models\Devices;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DeviceLogAlertController extends Controller
{
    private string $logPath = 'app/private';

    /**
     * Display the alert page
     */
    public function index()
    {
        return view('logs.alert');
    }

    /**
     * Scan all device_logs_*.txt files and build a date map
     */
    public function scan(Request $request)
    {
        $dir = storage_path($this->logPath);
        $files = glob($dir . '/device_logs_*.txt');

        if (empty($files)) {
            return response()->json(['dates' => [], 'files' => []]);
        }

        $dateMap = [];
        $fileList = [];

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $fileDate = $this->extractDateFromFilename($filename);

            if (!$fileDate) {
                continue;
            }

            $entryCount = 0;
            $dateCounts = [];

            $handle = @fopen($filePath, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '--') || str_starts_with($line, 'biometric_id')) {
                        continue;
                    }

                    $parts = array_map('trim', explode('|', $line));
                    if (count($parts) < 2) {
                        continue;
                    }

                    $dtrDate = $parts[1] ?? null;
                    if (!$dtrDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtrDate)) {
                        continue;
                    }

                    $entryCount++;
                    if (!isset($dateCounts[$dtrDate])) {
                        $dateCounts[$dtrDate] = 0;
                    }
                    $dateCounts[$dtrDate]++;
                }
                fclose($handle);
            }

            $fileList[] = [
                'filename' => $filename,
                'file_date' => $fileDate,
                'size' => $this->formatFileSize(File::size($filePath)),
                'entries' => $entryCount,
            ];

            foreach ($dateCounts as $dtrDate => $count) {
                if (!isset($dateMap[$dtrDate])) {
                    $dateMap[$dtrDate] = [];
                }

                $lateDays = $this->dateDiffDays($dtrDate, $fileDate);

                $dateMap[$dtrDate][] = [
                    'filename' => $filename,
                    'file_date' => $fileDate,
                    'count' => $count,
                    'late_days' => $lateDays,
                    'is_late' => $lateDays > 0,
                ];
            }
        }

        // Sort each date's files by file_date ascending
        foreach ($dateMap as &$entries) {
            usort($entries, fn($a, $b) => strcmp($a['file_date'], $b['file_date']));
        }
        unset($entries);

        // Sort file list by date descending
        usort($fileList, fn($a, $b) => strcmp($b['file_date'], $a['file_date']));

        // Build summary: which dates have late pulls
        $latePullDates = [];
        foreach ($dateMap as $date => $entries) {
            $hasLate = false;
            $totalLateFiles = 0;
            foreach ($entries as $entry) {
                if ($entry['is_late']) {
                    $hasLate = true;
                    $totalLateFiles++;
                }
            }
            if ($hasLate) {
                $latePullDates[$date] = $totalLateFiles;
            }
        }

        return response()->json([
            'dates' => $dateMap,
            'files' => $fileList,
            'late_pulls' => $latePullDates,
        ]);
    }

    /**
     * Scan device_logs database table and build a date map
     */
    public function scanDatabase(Request $request)
    {
        $dateCounts = DeviceLogs::selectRaw('dtr_date, COUNT(*) as count')
            ->whereNotNull('dtr_date')
            ->groupBy('dtr_date')
            ->get();

        $hrbCounts = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('device_logs_hrbliz')) {
            $hrbCounts = DeviceLogsHrbliz::selectRaw('dtr_date, COUNT(*) as count')
                ->whereNotNull('dtr_date')
                ->groupBy('dtr_date')
                ->get();
        }

        $dateMap = [];
        foreach ($dateCounts as $row) {
            $dateMap[$row->dtr_date] = ['count' => (int) $row->count];
        }

        foreach ($hrbCounts as $row) {
            if (isset($dateMap[$row->dtr_date])) {
                $dateMap[$row->dtr_date]['count'] += (int) $row->count;
            } else {
                $dateMap[$row->dtr_date] = ['count' => (int) $row->count];
            }
        }

        ksort($dateMap);

        if (empty($dateMap)) {
            return response()->json(['dates' => [], 'files' => [], 'late_pulls' => []]);
        }

        return response()->json([
            'dates' => $dateMap,
            'files' => [],
            'late_pulls' => [],
        ]);
    }

    /**
     * Get entries for a specific date from the database
     */
    public function dateEntries(Request $request, string $date)
    {
        $stdLogs = DeviceLogs::where('dtr_date', $date)->get();

        $hrbLogs = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('device_logs_hrbliz')) {
            $hrbLogs = DeviceLogsHrbliz::where('dtr_date', $date)->get();
        }

        $logs = $stdLogs->concat($hrbLogs)->sortBy('date_time')->values();

        $entries = [];
        foreach ($logs as $log) {
            $time = $log->date_time ? substr($log->date_time, 11, 8) : '';
            $isHrbliz = ($log instanceof DeviceLogsHrbliz) || (!empty($log->device_name) && str_contains(strtoupper($log->device_name), 'HRBLIZ'));
            $entries[] = [
                'biometric_id' => (string) $log->biometric_id,
                'name' => $log->name ?? '',
                'dtr_date' => $log->dtr_date,
                'dtr_time' => $time,
                'dtr_type' => (string) ($log->status ?? ''),
                'device_name' => $log->device_name ?? '',
                'is_hrbliz' => (bool) $isHrbliz,
                'created_at' => $log->created_at ? $log->created_at->format('Y-m-d h:i a') : '',
            ];
        }

        return response()->json([
            'date' => $date,
            'entries' => $entries,
            'total' => count($entries),
        ]);
    }

    /**
     * Get contents of a specific file
     */
    public function fileContents(Request $request, string $filename)
    {
        $dir = storage_path($this->logPath);
        $path = $dir . '/' . $filename;

        if (!File::exists($path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $parsed = [];
        $handle = @fopen($path, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '--') || str_starts_with($line, 'biometric_id')) {
                    continue;
                }

                $parts = array_map('trim', explode('|', $line));
                if (count($parts) < 2) {
                    continue;
                }

                $parsed[] = [
                    'biometric_id' => $parts[0] ?? '',
                    'dtr_date' => $parts[1] ?? '',
                    'name' => $parts[2] ?? '',
                    'dtr_time' => $parts[3] ?? '',
                    'dtr_type' => $parts[4] ?? '',
                    'device_name' => $parts[5] ?? '',
                ];
            }
            fclose($handle);
        }

        return response()->json([
            'filename' => $filename,
            'entries' => $parsed,
            'total' => count($parsed),
        ]);
    }

    /**
     * Print device logs or attendance logs for specific date(s) or date range with optional name/biometric_id filters
     */
    public function printDtrLogs(Request $request)
    {
        $request->validate([
            'date' => 'nullable|string',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'dates' => 'nullable',
            'name' => 'nullable|string',
            'biometric_id' => 'nullable|string',
            'is_via_attendance_logs' => 'nullable',
            'attendance_info_ids' => 'nullable',
        ]);

        $name = $request->input('name');
        $biometricId = $request->input('biometric_id');
        $isViaAttendanceLogs = filter_var($request->input('is_via_attendance_logs'), FILTER_VALIDATE_BOOLEAN);
        $attendanceInfoIds = $request->input('attendance_info_ids');
        if (is_string($attendanceInfoIds)) {
            $attendanceInfoIds = array_filter(array_map('trim', explode(',', $attendanceInfoIds)));
        }

        $datesList = $this->resolveDatesList($request);

        if (empty($datesList) && $request->input('date')) {
            $datesList = [$request->input('date')];
        }

        sort($datesList);

        if ($isViaAttendanceLogs) {
            $query = AttendanceInformation::with('attendance');

            if ($biometricId) {
                $query->where('biometric_id', $biometricId);
            } elseif ($name) {
                $query->where('name', 'LIKE', "%{$name}%");
            }

            if (!empty($attendanceInfoIds) && is_array($attendanceInfoIds)) {
                $query->whereIn('id', $attendanceInfoIds);
            } elseif (!empty($datesList)) {
                $query->where(function ($q) use ($datesList) {
                    $q->whereIn(DB::raw("DATE(first_entry)"), $datesList)
                      ->orWhereHas('attendance', function ($attQ) use ($datesList) {
                          $attQ->whereIn('open_date', $datesList);
                      });
                });
            }

            $rawInfo = $query->get();

            $logs = $rawInfo->map(function ($item) {
                $openDate = $item->attendance ? $item->attendance->open_date : null;
                $dtrDate = $openDate;
                if ($item->first_entry) {
                    $parts = explode(' ', $item->first_entry);
                    if (!$dtrDate) {
                        $dtrDate = $parts[0] ?? '';
                    }
                }
                $eventTitle = $item->attendance ? $item->attendance->title : 'Attendance Log';

                return (object) [
                    'biometric_id' => (string) $item->biometric_id,
                    'name' => $item->name,
                    'dtr_date' => $dtrDate,
                    'date_time' => $item->first_entry,
                    'created_at' => $item->created_at,
                    'device_name' => $eventTitle ? "{$eventTitle} (Via Attendance LOGs)" : "Via Attendance LOGs",
                ];
            })->sortBy(fn($x) => $x->dtr_date . ' ' . $x->date_time)->values();

            if (empty($datesList)) {
                $datesList = $logs->pluck('dtr_date')->unique()->filter()->values()->toArray();
                sort($datesList);
            }
        } else {
            $queryStd = DeviceLogs::query();
            $queryHrb = \Illuminate\Support\Facades\Schema::hasTable('device_logs_hrbliz')
                ? DeviceLogsHrbliz::query()
                : null;

            if (!empty($datesList)) {
                $queryStd->whereIn('dtr_date', $datesList);
                $queryHrb?->whereIn('dtr_date', $datesList);
            }

            if ($biometricId) {
                $queryStd->where('biometric_id', $biometricId);
                $queryHrb?->where('biometric_id', $biometricId);
            } elseif ($name) {
                $queryStd->where('name', 'LIKE', "%{$name}%");
                $queryHrb?->where('name', 'LIKE', "%{$name}%");
            }

            $stdLogs = $queryStd->get();
            $hrbLogs = $queryHrb ? $queryHrb->get() : collect();

            $logs = $stdLogs->concat($hrbLogs)
                ->sortBy(fn($x) => $x->dtr_date . ' ' . $x->date_time)
                ->values();
        }

        $dateLabel = 'All Dates';
        if (count($datesList) === 1) {
            $dateLabel = date('F j, Y', strtotime($datesList[0]));
        } elseif (count($datesList) > 1) {
            $minDate = date('F j, Y', strtotime(min($datesList)));
            $maxDate = date('F j, Y', strtotime(max($datesList)));
            $dateLabel = "{$minDate} – {$maxDate}";
        }

        $employeeName = $name ?: ($logs->first()->name ?? 'All Employees');
        $designation = null;
        $empId = '';

        if ($biometricId) {
            [$employeeName, $designation, $empId] = $this->resolveEmployeeDetails((string) $biometricId, $employeeName);
        }

        $pdf = Pdf::loadView('logs.print_dtr_logs', [
            'date' => $dateLabel,
            'dates' => $datesList,
            'logs' => $logs,
            'employeeName' => $employeeName,
            'biometricId' => $biometricId ?: '',
            'designation' => $designation,
            'empId' => $empId,
            'noData' => $logs->isEmpty(),
            'isMultipleDates' => count($datesList) > 1,
            'isViaAttendanceLogs' => $isViaAttendanceLogs,
        ])->setPaper('Letter', 'portrait');

        $sanitizedEmp = preg_replace('/[^A-Za-z0-9_]/', '_', $employeeName);
        $prefix = $isViaAttendanceLogs ? 'Attendance_Logs_' : 'DTR_Logs_';
        $filename = $prefix . $sanitizedEmp . '.pdf';
        return $pdf->stream($filename);
    }

    /**
     * Preview device logs or attendance logs for multiple dates / employee selection in the print modal
     */
    public function previewPrintLogs(Request $request)
    {
        $biometricId = $request->input('biometric_id');
        $name = $request->input('name');
        $isViaAttendanceLogs = filter_var($request->input('is_via_attendance_logs'), FILTER_VALIDATE_BOOLEAN);
        $attendanceInfoIds = $request->input('attendance_info_ids');
        if (is_string($attendanceInfoIds)) {
            $attendanceInfoIds = array_filter(array_map('trim', explode(',', $attendanceInfoIds)));
        }

        $datesList = $this->resolveDatesList($request);

        if ($isViaAttendanceLogs) {
            $query = AttendanceInformation::with('attendance');

            if ($biometricId) {
                $query->where('biometric_id', $biometricId);
            } elseif ($name) {
                $query->where('name', 'LIKE', "%{$name}%");
            }

            if (!empty($attendanceInfoIds) && is_array($attendanceInfoIds)) {
                $query->whereIn('id', $attendanceInfoIds);
            } elseif (!empty($datesList)) {
                $query->where(function ($q) use ($datesList) {
                    $q->whereIn(DB::raw("DATE(first_entry)"), $datesList)
                      ->orWhereHas('attendance', function ($attQ) use ($datesList) {
                          $attQ->whereIn('open_date', $datesList);
                      });
                });
            }

            $rawLogs = $query->get();

            $entries = [];
            foreach ($rawLogs as $log) {
                $openDate = $log->attendance ? $log->attendance->open_date : null;
                $dtrDate = $openDate;
                $dtrTime = '';
                if ($log->first_entry) {
                    $parts = explode(' ', $log->first_entry);
                    if (!$dtrDate) {
                        $dtrDate = $parts[0] ?? '';
                    }
                    $dtrTime = $parts[1] ?? '';
                }
                $eventTitle = $log->attendance ? $log->attendance->title : 'Attendance Log';

                $entries[] = [
                    'biometric_id' => (string) $log->biometric_id,
                    'name' => $log->name ?? '',
                    'dtr_date' => $dtrDate,
                    'dtr_time' => $dtrTime,
                    'dtr_type' => 'Attendance Log',
                    'device_name' => $eventTitle ? "{$eventTitle} (Via Attendance LOGs)" : "Via Attendance LOGs",
                    'created_at' => $log->created_at ? $log->created_at->format('Y-m-d h:i a') : '',
                    'is_via_attendance_logs' => true,
                ];
            }

            return response()->json([
                'entries' => $entries,
                'total' => count($entries),
                'dates' => $datesList,
                'is_via_attendance_logs' => true,
            ]);
        }

        $queryStd = DeviceLogs::query();
        $queryHrb = \Illuminate\Support\Facades\Schema::hasTable('device_logs_hrbliz')
            ? DeviceLogsHrbliz::query()
            : null;

        if ($biometricId) {
            $queryStd->where('biometric_id', $biometricId);
            $queryHrb?->where('biometric_id', $biometricId);
        } elseif ($name) {
            $queryStd->where('name', 'LIKE', "%{$name}%");
            $queryHrb?->where('name', 'LIKE', "%{$name}%");
        }

        if (!empty($datesList)) {
            $queryStd->whereIn('dtr_date', $datesList);
            $queryHrb?->whereIn('dtr_date', $datesList);
        }

        $stdLogs = $queryStd->get();
        $hrbLogs = $queryHrb ? $queryHrb->get() : collect();

        $logs = $stdLogs->concat($hrbLogs)
            ->sortBy(fn($x) => $x->dtr_date . ' ' . $x->date_time)
            ->values();

        $entries = [];
        foreach ($logs as $log) {
            $time = $log->date_time ? substr($log->date_time, 11, 8) : '';
            $isHrbliz = ($log instanceof DeviceLogsHrbliz) || (!empty($log->device_name) && str_contains(strtoupper($log->device_name), 'HRBLIZ'));
            $entries[] = [
                'biometric_id' => (string) $log->biometric_id,
                'name' => $log->name ?? '',
                'dtr_date' => $log->dtr_date,
                'dtr_time' => $time,
                'dtr_type' => (string) ($log->status ?? ''),
                'device_name' => $log->device_name ?? '',
                'is_hrbliz' => (bool) $isHrbliz,
                'created_at' => $log->created_at ? $log->created_at->format('Y-m-d h:i a') : '',
            ];
        }

        return response()->json([
            'entries' => $entries,
            'total' => count($entries),
            'dates' => $datesList,
        ]);
    }

    /**
     * Fetch specific attendance logs (AttendanceInformation) for a given employee
     */
    public function fetchAttendanceLogs(Request $request)
    {
        $biometricId = $request->query('biometric_id') ?? $request->input('biometric_id');

        if (!$biometricId) {
            return response()->json(['error' => 'biometric_id is required'], 400);
        }

        $infoLogs = AttendanceInformation::where('biometric_id', $biometricId)
            ->with('attendance')
            ->orderBy('first_entry', 'desc')
            ->get();

        $entries = [];
        foreach ($infoLogs as $info) {
            $openDate = $info->attendance ? $info->attendance->open_date : null;
            $eventTitle = $info->attendance ? $info->attendance->title : 'Attendance Log';

            $firstEntryDate = '';
            $firstEntryTime = '';
            if ($info->first_entry) {
                $parts = explode(' ', $info->first_entry);
                $firstEntryDate = $parts[0] ?? '';
                $firstEntryTime = $parts[1] ?? '';
            }

            $dtrDate = $firstEntryDate ?: ($openDate ?? '');
            $dtrTime = $firstEntryTime;

            $entries[] = [
                'id' => $info->id,
                'biometric_id' => (string) $info->biometric_id,
                'name' => $info->name ?? '',
                'event_title' => $eventTitle,
                'open_date' => $openDate ?? $dtrDate,
                'first_entry_date' => $firstEntryDate,
                'dtr_date' => $dtrDate,
                'dtr_time' => $dtrTime,
                'first_entry' => $info->first_entry,
                'last_entry' => $info->last_entry,
                'area' => $info->area ?? '',
                'areacode' => $info->areacode ?? '',
                'sector' => $info->sector ?? '',
                'device_name' => $eventTitle ? "{$eventTitle} (Attendance Log)" : "Via Attendance LOGs",
                'created_at' => $info->created_at ? $info->created_at->format('Y-m-d h:i a') : '',
            ];
        }

        return response()->json([
            'biometric_id' => $biometricId,
            'entries' => $entries,
            'total' => count($entries),
        ]);
    }

    /**
     * Resolve list of dates from request parameters (dates[], start_date/end_date, or single date)
     */
    private function resolveDatesList(Request $request): array
    {
        $datesInput = $request->input('dates') ?? $request->query('dates');

        if (!empty($datesInput)) {
            if (is_array($datesInput)) {
                return array_values(array_unique(array_filter($datesInput)));
            }
            if (is_string($datesInput)) {
                $split = explode(',', $datesInput);
                return array_values(array_unique(array_filter(array_map('trim', $split))));
            }
        }

        $startDate = $request->input('start_date') ?? $request->query('start_date');
        $endDate = $request->input('end_date') ?? $request->query('end_date');

        if ($startDate && $endDate) {
            $dates = [];
            $current = strtotime($startDate);
            $last = strtotime($endDate);

            if ($current > $last) {
                $tmp = $current;
                $current = $last;
                $last = $tmp;
            }

            while ($current <= $last) {
                $dates[] = date('Y-m-d', $current);
                $current = strtotime('+1 day', $current);
            }
            return $dates;
        }

        $singleDate = $request->input('date') ?? $request->query('date');
        if ($singleDate) {
            return [$singleDate];
        }

        return [];
    }

    /**
     * Search employees by name or biometric_id for the print modal dropdown
     */
    public function searchEmployees(Request $request)
    {
        $q = $request->query('q', '');

        $query = Biometrics::with('employeeProfile.personalInformation', 'externalProfile');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('biometric_id', 'LIKE', "%{$q}%")
                    ->orWhereHas('employeeProfile.personalInformation', function ($pi) use ($q) {
                        $pi->where('first_name', 'LIKE', "%{$q}%")
                            ->orWhere('last_name', 'LIKE', "%{$q}%");
                    })
                    ->orWhereHas('externalProfile', function ($ext) use ($q) {
                        $ext->where('first_name', 'LIKE', "%{$q}%")
                            ->orWhere('last_name', 'LIKE', "%{$q}%");
                    });
            });
        }

        $results = $query->limit(200)->get();

        $employees = [];
        foreach ($results as $bio) {
            $name = 'Unknown';
            $designation = null;
            $empId = '';

            if ($bio->employeeProfile && $bio->employeeProfile->personalInformation) {
                $pi = $bio->employeeProfile->personalInformation;
                $name = $pi->first_name . ' ' . $pi->last_name;
                $designation = $bio->employeeProfile->assignArea?->name ?? null;
                $empId = $bio->employeeProfile->employee_id ?? '';
            } elseif ($bio->externalProfile) {
                $name = trim(($bio->externalProfile->first_name ?? '') . ' ' . ($bio->externalProfile->last_name ?? '')) ?: 'Unknown';
                $designation = $bio->externalProfile->department ?? null;
                $empId = $bio->externalProfile->employee_id ?? '';
            }

            $employees[] = [
                'biometric_id' => (string) $bio->biometric_id,
                'name' => $name,
                'designation' => $designation,
                'employee_id' => $empId,
            ];
        }

        return response()->json($employees);
    }

    /**
     * Get list of devices for advanced filtering
     */
    public function getDevices(Request $request)
    {
        $deviceMap = [];

        $registeredDevices = Devices::orderBy('device_name')
            ->get(['device_name', 'ip_address', 'is_hrbliz', 'is_active']);

        foreach ($registeredDevices as $dev) {
            if (empty($dev->device_name)) {
                continue;
            }
            $deviceMap[$dev->device_name] = [
                'device_name' => $dev->device_name,
                'ip_address' => $dev->ip_address ?? '',
                'is_hrbliz' => (bool) $dev->is_hrbliz,
                'is_active' => (bool) $dev->is_active,
            ];
        }

        $stdNames = DeviceLogs::whereNotNull('device_name')
            ->where('device_name', '!=', '')
            ->distinct()
            ->pluck('device_name');

        foreach ($stdNames as $name) {
            if (!isset($deviceMap[$name])) {
                $deviceMap[$name] = [
                    'device_name' => $name,
                    'ip_address' => '',
                    'is_hrbliz' => str_contains(strtoupper($name), 'HRBLIZ'),
                    'is_active' => true,
                ];
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('device_logs_hrbliz')) {
            $hrbNames = DeviceLogsHrbliz::whereNotNull('device_name')
                ->where('device_name', '!=', '')
                ->distinct()
                ->pluck('device_name');

            foreach ($hrbNames as $name) {
                if (!isset($deviceMap[$name])) {
                    $deviceMap[$name] = [
                        'device_name' => $name,
                        'ip_address' => '',
                        'is_hrbliz' => true,
                        'is_active' => true,
                    ];
                }
            }
        }

        ksort($deviceMap, SORT_NATURAL | SORT_FLAG_CASE);

        return response()->json(array_values($deviceMap));
    }

    /**
     * Advanced filtering by date range, devices, and users (biometric_ids)
     */
    public function advancedFilter(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'source' => 'nullable|string|in:db,file',
        ]);

        $startDate = $request->input('start_date') ?? $request->query('start_date');
        $endDate = $request->input('end_date') ?? $request->query('end_date');
        $source = $request->input('source') ?? $request->query('source', 'db');

        if ($startDate && $endDate && $startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $devices = $request->input('devices') ?? $request->query('devices', []);
        if (is_string($devices)) {
            $devices = array_filter(array_map('trim', explode(',', $devices)));
        } elseif (!is_array($devices)) {
            $devices = [];
        }
        $devices = array_values(array_unique(array_filter($devices)));

        $biometricIds = $request->input('biometric_ids') ?? $request->query('biometric_ids', []);
        if (is_string($biometricIds)) {
            $biometricIds = array_filter(array_map('trim', explode(',', $biometricIds)));
        } elseif (!is_array($biometricIds)) {
            $biometricIds = [];
        }
        $singleBioId = $request->input('biometric_id') ?? $request->query('biometric_id');
        if ($singleBioId) {
            $biometricIds[] = (string) $singleBioId;
        }
        $biometricIds = array_values(array_unique(array_filter(array_map('strval', $biometricIds))));

        $maxLimit = 5000;
        $entries = [];
        $truncated = false;

        if ($source === 'file') {
            $dir = storage_path($this->logPath);
            $files = glob($dir . '/device_logs_*.txt') ?: [];

            foreach ($files as $filePath) {
                $handle = @fopen($filePath, 'r');
                if (!$handle) {
                    continue;
                }
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '--') || str_starts_with($line, 'biometric_id')) {
                        continue;
                    }
                    $parts = array_map('trim', explode('|', $line));
                    if (count($parts) < 2) {
                        continue;
                    }

                    $bioId = (string) ($parts[0] ?? '');
                    $dtrDate = $parts[1] ?? '';
                    $name = $parts[2] ?? '';
                    $dtrTime = $parts[3] ?? '';
                    $dtrType = $parts[4] ?? '';
                    $deviceName = $parts[5] ?? '';

                    if ($startDate && $dtrDate < $startDate) {
                        continue;
                    }
                    if ($endDate && $dtrDate > $endDate) {
                        continue;
                    }
                    if (!empty($devices) && !in_array($deviceName, $devices, true)) {
                        continue;
                    }
                    if (!empty($biometricIds) && !in_array($bioId, $biometricIds, true)) {
                        continue;
                    }

                    $entries[] = [
                        'biometric_id' => $bioId,
                        'name' => $name,
                        'dtr_date' => $dtrDate,
                        'dtr_time' => $dtrTime,
                        'dtr_type' => (string) $dtrType,
                        'device_name' => $deviceName,
                        'is_hrbliz' => str_contains(strtoupper($deviceName), 'HRBLIZ'),
                        'created_at' => basename($filePath),
                    ];

                    if (count($entries) >= $maxLimit) {
                        $truncated = true;
                        break;
                    }
                }
                fclose($handle);
                if ($truncated) {
                    break;
                }
            }

            usort($entries, fn($a, $b) => strcmp($a['dtr_date'] . ' ' . $a['dtr_time'], $b['dtr_date'] . ' ' . $b['dtr_time']));
        } else {
            $queryStd = DeviceLogs::query();
            $queryHrb = \Illuminate\Support\Facades\Schema::hasTable('device_logs_hrbliz')
                ? DeviceLogsHrbliz::query()
                : null;

            if ($startDate) {
                $queryStd->where('dtr_date', '>=', $startDate);
                $queryHrb?->where('dtr_date', '>=', $startDate);
            }
            if ($endDate) {
                $queryStd->where('dtr_date', '<=', $endDate);
                $queryHrb?->where('dtr_date', '<=', $endDate);
            }
            if (!empty($devices)) {
                $queryStd->whereIn('device_name', $devices);
                $queryHrb?->whereIn('device_name', $devices);
            }
            if (!empty($biometricIds)) {
                $queryStd->whereIn('biometric_id', $biometricIds);
                $queryHrb?->whereIn('biometric_id', $biometricIds);
            }

            $stdLogs = $queryStd->orderBy('date_time', 'asc')->limit($maxLimit + 1)->get();
            $hrbLogs = $queryHrb ? $queryHrb->orderBy('date_time', 'asc')->limit($maxLimit + 1)->get() : collect();

            $logs = $stdLogs->concat($hrbLogs)
                ->sortBy('date_time')
                ->values();

            if ($logs->count() > $maxLimit) {
                $truncated = true;
                $logs = $logs->take($maxLimit);
            }

            foreach ($logs as $log) {
                $time = $log->date_time ? substr($log->date_time, 11, 8) : '';
                $isHrbliz = ($log instanceof DeviceLogsHrbliz) || (!empty($log->device_name) && str_contains(strtoupper($log->device_name), 'HRBLIZ'));
                $entries[] = [
                    'biometric_id' => (string) $log->biometric_id,
                    'name' => $log->name ?? '',
                    'dtr_date' => $log->dtr_date,
                    'dtr_time' => $time,
                    'dtr_type' => (string) ($log->status ?? ''),
                    'device_name' => $log->device_name ?? '',
                    'is_hrbliz' => (bool) $isHrbliz,
                    'created_at' => $log->created_at ? $log->created_at->format('Y-m-d h:i a') : '',
                ];
            }
        }

        return response()->json([
            'entries' => $entries,
            'total' => count($entries),
            'truncated' => $truncated,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'devices' => $devices,
            'biometric_ids' => $biometricIds,
            'source' => $source,
        ]);
    }

    /**
     * Print advanced filter results separated by user name (each user gets their own DTR Logs section/page)
     */
    public function printFilteredLogs(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'source' => 'nullable|string|in:db,file',
        ]);

        $startDate = $request->input('start_date') ?? $request->query('start_date');
        $endDate = $request->input('end_date') ?? $request->query('end_date');
        $source = $request->input('source') ?? $request->query('source', 'db');

        if ($startDate && $endDate && $startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $devices = $request->input('devices') ?? $request->query('devices', []);
        if (is_string($devices)) {
            $devices = array_filter(array_map('trim', explode(',', $devices)));
        } elseif (!is_array($devices)) {
            $devices = [];
        }
        $devices = array_values(array_unique(array_filter($devices)));

        $biometricIds = $request->input('biometric_ids') ?? $request->query('biometric_ids', []);
        if (is_string($biometricIds)) {
            $biometricIds = array_filter(array_map('trim', explode(',', $biometricIds)));
        } elseif (!is_array($biometricIds)) {
            $biometricIds = [];
        }
        $biometricIds = array_values(array_unique(array_filter(array_map('strval', $biometricIds))));

        $localBio = strtolower(trim((string) ($request->input('local_bio') ?? '')));
        $localName = strtolower(trim((string) ($request->input('local_name') ?? '')));
        $localDate = strtolower(trim((string) ($request->input('local_date') ?? '')));
        $localDevice = strtolower(trim((string) ($request->input('local_device') ?? '')));

        $logs = collect();

        if ($source === 'file') {
            $dir = storage_path($this->logPath);
            $files = glob($dir . '/device_logs_*.txt') ?: [];
            $items = [];

            foreach ($files as $filePath) {
                $handle = @fopen($filePath, 'r');
                if (!$handle) {
                    continue;
                }
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '--') || str_starts_with($line, 'biometric_id')) {
                        continue;
                    }
                    $parts = array_map('trim', explode('|', $line));
                    if (count($parts) < 2) {
                        continue;
                    }

                    $bioId = (string) ($parts[0] ?? '');
                    $dtrDate = $parts[1] ?? '';
                    $name = $parts[2] ?? '';
                    $dtrTime = $parts[3] ?? '';
                    $deviceName = $parts[5] ?? '';

                    if ($startDate && $dtrDate < $startDate) continue;
                    if ($endDate && $dtrDate > $endDate) continue;
                    if (!empty($devices) && !in_array($deviceName, $devices, true)) continue;
                    if (!empty($biometricIds) && !in_array($bioId, $biometricIds, true)) continue;

                    $items[] = (object) [
                        'biometric_id' => $bioId,
                        'name' => $name,
                        'dtr_date' => $dtrDate,
                        'date_time' => trim($dtrDate . ' ' . $dtrTime),
                        'created_at' => null,
                        'device_name' => $deviceName,
                        'is_hrbliz' => str_contains(strtoupper($deviceName), 'HRBLIZ'),
                    ];
                }
                fclose($handle);
            }

            $logs = collect($items);
        } else {
            $queryStd = DeviceLogs::query();
            $queryHrb = \Illuminate\Support\Facades\Schema::hasTable('device_logs_hrbliz')
                ? DeviceLogsHrbliz::query()
                : null;

            if ($startDate) {
                $queryStd->where('dtr_date', '>=', $startDate);
                $queryHrb?->where('dtr_date', '>=', $startDate);
            }
            if ($endDate) {
                $queryStd->where('dtr_date', '<=', $endDate);
                $queryHrb?->where('dtr_date', '<=', $endDate);
            }
            if (!empty($devices)) {
                $queryStd->whereIn('device_name', $devices);
                $queryHrb?->whereIn('device_name', $devices);
            }
            if (!empty($biometricIds)) {
                $queryStd->whereIn('biometric_id', $biometricIds);
                $queryHrb?->whereIn('biometric_id', $biometricIds);
            }

            $stdLogs = $queryStd->orderBy('date_time', 'asc')->limit(5000)->get();
            $hrbLogs = $queryHrb ? $queryHrb->orderBy('date_time', 'asc')->limit(5000)->get() : collect();
            $logs = $stdLogs->concat($hrbLogs);
        }

        if ($localBio !== '' || $localName !== '' || $localDate !== '' || $localDevice !== '') {
            $logs = $logs->filter(function ($log) use ($localBio, $localName, $localDate, $localDevice) {
                $bio = strtolower((string) ($log->biometric_id ?? ''));
                $name = strtolower((string) ($log->name ?? ''));
                $dtrDate = strtolower((string) ($log->dtr_date ?? ''));
                $dev = strtolower((string) ($log->device_name ?? ''));
                $isHrbliz = ($log instanceof DeviceLogsHrbliz) || (!empty($log->is_hrbliz)) || str_contains(strtoupper($log->device_name ?? ''), 'HRBLIZ');

                if ($localBio !== '' && !str_contains($bio, $localBio)) return false;
                if ($localName !== '' && !str_contains($name, $localName)) return false;
                if ($localDate !== '' && !str_contains($dtrDate, $localDate)) return false;
                if ($localDevice !== '' && !str_contains($dev, $localDevice) && !($isHrbliz && str_contains('hrbliz', $localDevice))) return false;
                return true;
            });
        }

        $logs = $logs->sortBy(fn($x) => ($x->dtr_date ?? '') . ' ' . ($x->date_time ?? ''))->values();

        $datesList = $logs->pluck('dtr_date')->unique()->filter()->sort()->values()->toArray();
        $dateLabel = 'All Dates';
        if ($startDate && $endDate) {
            if ($startDate === $endDate) {
                $dateLabel = date('F j, Y', strtotime($startDate));
            } else {
                $dateLabel = date('F j, Y', strtotime($startDate)) . ' – ' . date('F j, Y', strtotime($endDate));
            }
        } elseif ($startDate) {
            $dateLabel = 'From ' . date('F j, Y', strtotime($startDate));
        } elseif ($endDate) {
            $dateLabel = 'Up to ' . date('F j, Y', strtotime($endDate));
        } elseif (count($datesList) === 1) {
            $dateLabel = date('F j, Y', strtotime($datesList[0]));
        } elseif (count($datesList) > 1) {
            $dateLabel = date('F j, Y', strtotime(min($datesList))) . ' – ' . date('F j, Y', strtotime(max($datesList)));
        }

        $grouped = $logs->groupBy(function ($item) {
            $bio = trim((string) ($item->biometric_id ?? ''));
            return $bio !== '' ? 'bio:' . $bio : 'name:' . strtolower(trim((string) ($item->name ?? 'Unknown')));
        });

        $userSections = [];
        foreach ($grouped as $userLogs) {
            $first = $userLogs->first();
            $bioId = trim((string) ($first->biometric_id ?? ''));
            $fallbackName = trim((string) ($first->name ?? '')) ?: 'Unknown Employee';

            if ($bioId !== '') {
                [$employeeName, $designation, $empId] = $this->resolveEmployeeDetails($bioId, $fallbackName);
            } else {
                $employeeName = $fallbackName;
                $designation = null;
                $empId = '';
            }

            $sortedUserLogs = $userLogs->sortBy(fn($x) => ($x->dtr_date ?? '') . ' ' . ($x->date_time ?? ''))->values();
            $userDatesCount = $sortedUserLogs->pluck('dtr_date')->unique()->filter()->count();

            $userSections[] = [
                'employeeName' => $employeeName,
                'biometricId' => $bioId,
                'designation' => $designation,
                'empId' => $empId,
                'logs' => $sortedUserLogs,
                'isMultipleDates' => $userDatesCount > 1,
            ];
        }

        usort($userSections, fn($a, $b) => strcasecmp($a['employeeName'], $b['employeeName']));

        if (empty($userSections)) {
            $userSections[] = [
                'employeeName' => 'No Matching Users',
                'biometricId' => '',
                'designation' => null,
                'empId' => '',
                'logs' => collect(),
                'isMultipleDates' => false,
            ];
        }

        $pdf = Pdf::loadView('logs.print_dtr_logs', [
            'date' => $dateLabel,
            'dates' => $datesList,
            'logs' => $logs,
            'employeeName' => count($userSections) === 1 ? $userSections[0]['employeeName'] : 'Filtered Users',
            'biometricId' => count($userSections) === 1 ? $userSections[0]['biometricId'] : '',
            'designation' => count($userSections) === 1 ? $userSections[0]['designation'] : null,
            'empId' => count($userSections) === 1 ? $userSections[0]['empId'] : '',
            'noData' => $logs->isEmpty(),
            'isMultipleDates' => count($datesList) > 1,
            'isViaAttendanceLogs' => false,
            'userSections' => $userSections,
        ])->setPaper('Letter', 'portrait');

        return $pdf->stream('DTR_Logs_Filtered.pdf');
    }

    /**
     * Resolve employee display name, designation, and employee_id from Biometrics
     */
    private function resolveEmployeeDetails(string $biometricId, string $fallbackName): array
    {
        $employeeName = $fallbackName;
        $designation = null;
        $empId = '';

        if ((int) $biometricId >= 8000) {
            $biometric = Biometrics::where('biometric_id', $biometricId)
                ->with('externalProfile')
                ->first();

            if ($biometric && $biometric->externalProfile) {
                $employeeName = trim(($biometric->externalProfile->first_name ?? '') . ' ' . ($biometric->externalProfile->last_name ?? '')) ?: $employeeName;
                $designation = $biometric->externalProfile->department ?? null;
                $empId = $biometric->externalProfile->employee_id ?? '';
            }
        } else {
            $biometric = Biometrics::where('biometric_id', $biometricId)
                ->with('employeeProfile.assignArea.department', 'employeeProfile.assignArea.section', 'employeeProfile.assignArea.unit', 'employeeProfile.assignArea.division', 'employeeProfile.personalInformation', 'externalProfile')
                ->first();

            if ($biometric && $biometric->employeeProfile) {
                $employeeName = $biometric->employeeProfile->personalInformation
                    ? $biometric->employeeProfile->personalInformation->first_name . ' ' . $biometric->employeeProfile->personalInformation->last_name
                    : $employeeName;
                $assignArea = $biometric->employeeProfile->assignArea;
                if ($assignArea) {
                    if ($assignArea->department) {
                        $designation = $assignArea->department->name;
                    } elseif ($assignArea->section) {
                        $designation = $assignArea->section->name;
                    } elseif ($assignArea->unit) {
                        $designation = $assignArea->unit->name;
                    } elseif ($assignArea->division) {
                        $designation = $assignArea->division->name;
                    }
                }
                $empId = $biometric->employeeProfile->employee_id ?? '';
            } elseif ($biometric && $biometric->externalProfile) {
                $employeeName = trim(($biometric->externalProfile->first_name ?? '') . ' ' . ($biometric->externalProfile->last_name ?? '')) ?: $employeeName;
                $designation = $biometric->externalProfile->department ?? null;
                $empId = $biometric->externalProfile->employee_id ?? '';
            }
        }

        return [$employeeName, $designation, $empId];
    }

    /**
     * Generate new device_logs from selected text file records.
     * Checks if a record already exists before inserting.
     */
    public function generateDeviceLogs(Request $request)
    {
        $request->validate([
            'entries' => 'required|array|min:1',
            'entries.*.biometric_id' => 'required',
            'entries.*.dtr_date' => 'required|date_format:Y-m-d',
            'entries.*.dtr_time' => 'required',
        ]);

        $entries = $request->input('entries');
        $createdCount = 0;
        $skippedCount = 0;

        foreach ($entries as $entry) {
            $biometricId = $entry['biometric_id'];
            $dtrDate = $entry['dtr_date'];
            $dtrTime = trim($entry['dtr_time']);
            $name = !empty($entry['name']) ? trim($entry['name']) : 'Unknown';
            $status = isset($entry['dtr_type']) ? (string) $entry['dtr_type'] : '0';
            $deviceName = !empty($entry['device_name']) ? trim($entry['device_name']) : 'Unknown';
            $dateTime = $dtrDate . ' ' . $dtrTime;

            $device = Devices::where('device_name', $deviceName)->first();
            $isHrbliz = ($device && (bool)$device->is_hrbliz)
                || (!empty($entry['is_hrbliz']))
                || str_contains(strtoupper($deviceName), 'HRBLIZ');

            $existsStd = DeviceLogs::where('biometric_id', $biometricId)
                ->where('date_time', $dateTime)
                ->exists();

            $existsHrb = false;
            if (\Illuminate\Support\Facades\Schema::hasTable('device_logs_hrbliz')) {
                $existsHrb = DeviceLogsHrbliz::where('biometric_id', $biometricId)
                    ->where('date_time', $dateTime)
                    ->exists();
            }

            if ($existsStd || $existsHrb) {
                $skippedCount++;
                continue;
            }

            $targetModel = ($isHrbliz && \Illuminate\Support\Facades\Schema::hasTable('device_logs_hrbliz'))
                ? DeviceLogsHrbliz::class
                : DeviceLogs::class;

            $targetModel::create([
                'biometric_id' => $biometricId,
                'name' => $name,
                'dtr_date' => $dtrDate,
                'date_time' => $dateTime,
                'status' => $status,
                'is_Shifting' => 0,
                'schedule' => null,
                'active' => 1,
                'device_name' => $deviceName,
            ]);

            $createdCount++;
        }

        return response()->json([
            'success' => true,
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
            'total' => count($entries),
            'message' => "Generated {$createdCount} new device log(s). {$skippedCount} existing record(s) were skipped.",
        ]);
    }

    /**
     * Extract date from filename like device_logs_2026-06-24.txt
     */
    private function extractDateFromFilename(string $filename): ?string
    {
        if (preg_match('/device_logs_(\d{4}-\d{2}-\d{2})\.txt$/', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Calculate difference in days between two dates
     */
    private function dateDiffDays(string $from, string $to): int
    {
        $fromTs = strtotime($from);
        $toTs = strtotime($to);
        return (int) round(($toTs - $fromTs) / 86400);
    }

    /**
     * Format file size
     */
    private function formatFileSize($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
