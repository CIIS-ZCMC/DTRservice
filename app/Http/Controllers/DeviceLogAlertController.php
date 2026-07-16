<?php

namespace App\Http\Controllers;

use App\Models\Biometrics;
use App\Models\DeviceLogs;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
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
     * Print device logs for a specific date with optional name/biometric_id filters
     */
    public function printDtrLogs(Request $request)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'name' => 'nullable|string',
            'biometric_id' => 'nullable|string',
        ]);

        $date = $request->input('date');
        $name = $request->input('name');
        $biometricId = $request->input('biometric_id');

        $query = DeviceLogs::where('dtr_date', $date);

        if ($biometricId) {
            $query->where('biometric_id', $biometricId);
        }

        if ($name) {
            $query->where('name', 'LIKE', "%{$name}%");
        }

        $logs = $query->orderBy('date_time')->get();

        if ($logs->isEmpty()) {
            $pdf = Pdf::loadView('logs.print_dtr_logs', [
                'date' => $date,
                'logs' => [],
                'employeeName' => $name ?: 'All Employees',
                'biometricId' => $biometricId ?: '',
                'designation' => null,
                'empId' => '',
                'noData' => true,
            ])->setPaper('Letter', 'portrait');
            return $pdf->stream("DTR_Logs_{$date}.pdf");
        }

        $employeeName = $name ?: $logs->first()->name ?? 'All Employees';
        $designation = null;
        $empId = '';

        if ($biometricId) {
            $biometric = Biometrics::where('biometric_id', $biometricId)
                ->with('employeeProfile', 'externalProfile')
                ->first();

            if ($biometric && $biometric->employeeProfile) {
                $employeeName = $biometric->employeeProfile->personalInformation
                    ? $biometric->employeeProfile->personalInformation->first_name . ' ' . $biometric->employeeProfile->personalInformation->last_name
                    : $employeeName;
                $designation = $biometric->employeeProfile->assignArea ?? null;
                $empId = $biometric->employeeProfile->employee_id ?? '';
            } elseif ($biometric && $biometric->externalProfile) {
                $employeeName = trim(($biometric->externalProfile->first_name ?? '') . ' ' . ($biometric->externalProfile->last_name ?? '')) ?: $employeeName;
                $designation = $biometric->externalProfile->department ?? null;
                $empId = $biometric->externalProfile->employee_id ?? '';
            }
        }

        $pdf = Pdf::loadView('logs.print_dtr_logs', [
            'date' => $date,
            'logs' => $logs,
            'employeeName' => $employeeName,
            'biometricId' => $biometricId ?: '',
            'designation' => $designation,
            'empId' => $empId,
            'noData' => false,
        ])->setPaper('Letter', 'portrait');

        $filename = $date . '_DTR_Logs_' . $employeeName . '.pdf';
        return $pdf->stream($filename);
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

        $results = $query->limit(50)->get();

        $employees = [];
        foreach ($results as $bio) {
            $name = 'Unknown';
            $designation = null;
            $empId = '';

            if ($bio->employeeProfile && $bio->employeeProfile->personalInformation) {
                $pi = $bio->employeeProfile->personalInformation;
                $name = $pi->first_name . ' ' . $pi->last_name;
                $designation = $bio->employeeProfile->assignArea?->area_name ?? null;
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
