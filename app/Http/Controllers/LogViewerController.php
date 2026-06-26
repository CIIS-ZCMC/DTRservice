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

        return response()->json([
            'laravel' => $laravelLog,
            'device' => $deviceLog
        ]);
    }

    /**
     * Clear a specific log file
     */
    public function clear(Request $request)
    {
        $filename = $request->get('file');

        // Security: only allow specific log files
        $allowedFiles = ['laravel.log', 'device_logs.log'];
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
     * Read a specific log file
     */
    private function readLogFile($filename, $lines)
    {
        $path = storage_path('logs/' . $filename);

        if (!File::exists($path)) {
            return [
                'filename' => $filename,
                'exists' => false,
                'lines' => [],
                'total_lines' => 0
            ];
        }

        $content = File::get($path);
        $linesArray = explode("\n", $content);

        // Get the last N lines
        $linesArray = array_slice($linesArray, -$lines);

        return [
            'filename' => $filename,
            'exists' => true,
            'lines' => $linesArray,
            'total_lines' => count(explode("\n", $content)),
            'size' => $this->formatFileSize(File::size($path)),
            'modified' => date('Y-m-d H:i:s', File::lastModified($path))
        ];
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
