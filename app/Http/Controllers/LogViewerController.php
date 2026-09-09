<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class LogViewerController extends Controller
{
    /**
     * Display the log viewer page
     */
    public function index()
    {
        return view('logs.index');
    }

    /**
     * Get the content of both Laravel and device logs
     */
    public function show(Request $request)
    {
        $lines = (int) $request->get('lines', 100);

        $laravelLog = $this->readLogFile('laravel.log', $lines);
        $deviceLog = $this->readLogFile('device_logs.log', $lines);
        $registrationLog = $this->readLogFile('registration_logs.log', $lines);

        return response()->json([
            'laravel' => $laravelLog,
            'device' => $deviceLog,
            'registration' => $registrationLog,
        ]);
    }

    /**
     * Clear a specific log file
     */
    public function clear(Request $request)
    {
        $filename = $request->get('file');

        // Security: only allow specific log files
        $allowedFiles = ['laravel.log', 'device_logs.log', 'registration_logs.log'];
        if (!in_array($filename, $allowedFiles)) {
            return response()->json(['error' => 'Invalid file'], 400);
        }

        $path = storage_path('logs/' . $filename);

        if (!File::exists($path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        try {
            File::put($path, '');
            return response()->json(['success' => true, 'message' => "Cleared {$filename}"]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to clear file: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Read a specific log file efficiently without loading the entire file into memory
     */
    private function readLogFile($filename, $lines)
    {
        $path = storage_path('logs/' . $filename);

        if (!File::exists($path)) {
            // Also check for daily rotated logs if direct filename doesn't exist
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $dailyFiles = glob(storage_path('logs/' . $baseName . '-*.log')) ?: [];
            if (!empty($dailyFiles)) {
                usort($dailyFiles, fn($a, $b) => filemtime($b) - filemtime($a));
                $path = $dailyFiles[0];
                $filename = basename($path);
            } else {
                return [
                    'filename' => $filename,
                    'exists' => false,
                    'lines' => [],
                    'total_lines' => 0
                ];
            }
        }

        $fileSize = File::size($path);
        if ($fileSize === 0) {
            return [
                'filename' => $filename,
                'exists' => true,
                'lines' => [],
                'total_lines' => 0,
                'size' => $this->formatFileSize(0),
                'modified' => date('Y-m-d H:i:s', File::lastModified($path))
            ];
        }

        $linesArray = $this->tailFile($path, $lines);
        $totalLines = $this->countTotalLines($path);

        return [
            'filename' => $filename,
            'exists' => true,
            'lines' => $linesArray,
            'total_lines' => $totalLines,
            'size' => $this->formatFileSize($fileSize),
            'modified' => date('Y-m-d H:i:s', File::lastModified($path))
        ];
    }

    /**
     * Tail the last N lines from a file using backward chunked seeking (O(1) memory)
     */
    private function tailFile(string $filepath, int $lines = 100): array
    {
        $handle = @fopen($filepath, 'rb');
        if (!$handle) {
            return [];
        }

        $bufferSize = 8192;
        $output = '';
        $lineCount = 0;

        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);
        $offset = $fileSize;

        while ($offset > 0 && $lineCount <= $lines) {
            $readSize = min($bufferSize, $offset);
            $offset -= $readSize;

            fseek($handle, $offset);
            $chunk = fread($handle, $readSize);
            $output = $chunk . $output;

            $lineCount = substr_count($output, "\n");
        }

        fclose($handle);

        $allLines = explode("\n", $output);
        if (!empty($allLines) && end($allLines) === '') {
            array_pop($allLines);
        }

        return array_slice($allLines, -$lines);
    }

    /**
     * Count total lines by streaming 64KB chunks to avoid memory exhaustion
     */
    private function countTotalLines(string $filepath): int
    {
        $handle = @fopen($filepath, 'rb');
        if (!$handle) {
            return 0;
        }

        $totalLines = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            $totalLines += substr_count($chunk, "\n");
        }
        fclose($handle);

        return $totalLines;
    }

    /**
     * Get all log files in storage/logs
     */
    private function getLogFiles(): array
    {
        $logPath = storage_path('logs');
        $files = File::files($logPath);

        $logFiles = [];
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.log')) {
                $logFiles[] = [
                    'name' => $file->getFilename(),
                    'size' => $this->formatFileSize($file->getSize()),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                ];
            }
        }

        // Sort by modified time (newest first)
        usort($logFiles, function ($a, $b) {
            return strtotime($b['modified']) - strtotime($a['modified']);
        });

        return $logFiles;
    }

    /**
     * Format file size for display
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
