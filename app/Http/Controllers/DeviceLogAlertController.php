<?php

namespace App\Http\Controllers;

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
