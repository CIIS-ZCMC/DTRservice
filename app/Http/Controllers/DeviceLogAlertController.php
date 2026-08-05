<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceInformation;
use App\Models\Biometrics;
use App\Models\DeviceLogs;
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

            $content = File::get($filePath);
            $lines = explode("\n", $content);

            $entryCount = 0;
            $dateCounts = [];

            foreach ($lines as $line) {
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
            ->orderBy('dtr_date')
            ->get();

        if ($dateCounts->isEmpty()) {
            return response()->json(['dates' => [], 'files' => [], 'late_pulls' => []]);
        }

        $dateMap = [];
        foreach ($dateCounts as $row) {
            $dateMap[$row->dtr_date] = ['count' => $row->count];
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
        $logs = DeviceLogs::where('dtr_date', $date)
            ->orderBy('date_time')
            ->get();

        $entries = [];
        foreach ($logs as $log) {
            $time = $log->date_time ? substr($log->date_time, 11, 8) : '';
            $entries[] = [
                'biometric_id' => (string) $log->biometric_id,
                'name' => $log->name ?? '',
                'dtr_date' => $log->dtr_date,
                'dtr_time' => $time,
                'dtr_type' => (string) ($log->status ?? ''),
                'device_name' => $log->device_name ?? '',
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

        $content = File::get($path);
        $lines = explode("\n", $content);

        $parsed = [];
        foreach ($lines as $line) {
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
            $query = DeviceLogs::query();

            if (!empty($datesList)) {
                $query->whereIn('dtr_date', $datesList);
            }

            if ($biometricId) {
                $query->where('biometric_id', $biometricId);
            } elseif ($name) {
                $query->where('name', 'LIKE', "%{$name}%");
            }

            $logs = $query->orderBy('dtr_date')->orderBy('date_time')->get();
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

        $query = DeviceLogs::query();

        if ($biometricId) {
            $query->where('biometric_id', $biometricId);
        } elseif ($name) {
            $query->where('name', 'LIKE', "%{$name}%");
        }

        if (!empty($datesList)) {
            $query->whereIn('dtr_date', $datesList);
        }

        $logs = $query->orderBy('dtr_date')->orderBy('date_time')->get();

        $entries = [];
        foreach ($logs as $log) {
            $time = $log->date_time ? substr($log->date_time, 11, 8) : '';
            $entries[] = [
                'biometric_id' => (string) $log->biometric_id,
                'name' => $log->name ?? '',
                'dtr_date' => $log->dtr_date,
                'dtr_time' => $time,
                'dtr_type' => (string) ($log->status ?? ''),
                'device_name' => $log->device_name ?? '',
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

            $dtrDate = $openDate;
            $dtrTime = '';
            if ($info->first_entry) {
                $parts = explode(' ', $info->first_entry);
                if (!$dtrDate) {
                    $dtrDate = $parts[0] ?? '';
                }
                $dtrTime = $parts[1] ?? '';
            }

            $entries[] = [
                'id' => $info->id,
                'biometric_id' => (string) $info->biometric_id,
                'name' => $info->name ?? '',
                'event_title' => $eventTitle,
                'open_date' => $openDate ?? $dtrDate,
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

            $exists = DeviceLogs::where('biometric_id', $biometricId)
                ->where('date_time', $dateTime)
                ->exists();

            if ($exists) {
                $skippedCount++;
                continue;
            }

            DeviceLogs::create([
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
