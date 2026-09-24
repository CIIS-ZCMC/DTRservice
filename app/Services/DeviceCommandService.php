<?php

namespace App\Services;

use App\Models\Devices;
use Illuminate\Support\Facades\Log;

class DeviceCommandService
{
    protected string $baseDir;
    protected int $maxSizeBytes;
    protected static ?array $previousPendingCache = null;
    protected static array $deviceNameCache = [];

    /**
     * @param string|null $baseDir Base directory for per-device queues. Defaults to storage/app/device_queues
     * @param int $maxSizeBytes Maximum size per file before creating a new numbered file. Defaults to 50MB (52,428,800 bytes)
     */
    public function __construct(?string $baseDir = null, int $maxSizeBytes = 52428800)
    {
        if ($baseDir !== null) {
            if (str_ends_with(strtolower($baseDir), '.json')) {
                // If a path ending with .json was passed, use a subfolder in its directory
                $this->baseDir = dirname($baseDir) . DIRECTORY_SEPARATOR . 'device_queues';
            } else {
                $this->baseDir = rtrim($baseDir, '/\\');
            }
        } elseif (app()->runningUnitTests() || config('app.env') === 'testing') {
            $this->baseDir = storage_path('framework/testing/device_queues');
        } else {
            $this->baseDir = storage_path('app/device_queues');
        }

        $this->maxSizeBytes = $maxSizeBytes;

        $this->ensureBaseDirectory();
        $this->migrateLegacyFileIfNeeded();
    }

    /**
     * Ensure the base queue directory exists.
     */
    protected function ensureBaseDirectory(): void
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0755, true);
        }
    }

    /**
     * Get the base directory containing per-device command queue files.
     */
    public function getBaseDirectory(): string
    {
        return $this->baseDir;
    }

    /**
     * Sanitize a device name so it is safe for filenames on Windows, Linux, and macOS.
     * Replaces characters: \ / : * ? " < > | with _
     */
    public function getSanitizedDeviceName(string $name): string
    {
        $safe = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', trim($name));
        $safe = preg_replace('/[\x00-\x1F\x7F]/', '', $safe);
        return $safe !== '' ? $safe : 'unknown_device';
    }

    /**
     * Resolve the device name for a given device serial number.
     * Looks up Devices table if available; falls back to serial number.
     */
    public function getDeviceNameForSn(string $deviceSn): string
    {
        if (isset(self::$deviceNameCache[$deviceSn])) {
            return self::$deviceNameCache[$deviceSn];
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('devices')) {
            try {
                $name = Devices::where('serial_number', $deviceSn)->value('device_name');
                if (!empty($name)) {
                    $sanitized = $this->getSanitizedDeviceName($name);
                    self::$deviceNameCache[$deviceSn] = $sanitized;
                    return $sanitized;
                }
            } catch (\Throwable $e) {
                // Table might not exist yet during migration
            }
        }

        $sanitized = $this->getSanitizedDeviceName($deviceSn);
        self::$deviceNameCache[$deviceSn] = $sanitized;
        return $sanitized;
    }

    /**
     * Get the standardized file prefix for a device: {deviceName}_({serialNumber})
     */
    public function getDeviceFilePrefix(string $deviceSn): string
    {
        $deviceName = $this->getDeviceNameForSn($deviceSn);
        $sn = $this->getSanitizedDeviceName($deviceSn);
        return "{$deviceName}_({$sn})";
    }

    /**
     * Get the primary queue file path for a specific device: {deviceName}_({serialNumber}).json
     */
    public function getDeviceQueueFile(string $deviceSn): string
    {
        $prefix = $this->getDeviceFilePrefix($deviceSn);
        return $this->baseDir . DIRECTORY_SEPARATOR . "{$prefix}.json";
    }

    /**
     * Get all command files for a specific device in chronological order (main + rotated numbered files).
     * Discovers files by hardware serial number *({serialNumber})*.json as well as exact prefix.
     *
     * @param string $deviceSn
     * @return array<string>
     */
    public function getAllCommandFilesForDevice(string $deviceSn): array
    {
        $prefix = $this->getDeviceFilePrefix($deviceSn);
        $sanitizedSn = $this->getSanitizedDeviceName($deviceSn);
        $primary = $this->baseDir . DIRECTORY_SEPARATOR . "{$prefix}.json";

        $files = [];

        // 1. Check for files matching current prefix: {prefix}.json and {prefix}_{number}.json
        $currentMatches = glob($this->baseDir . DIRECTORY_SEPARATOR . $prefix . '*.json') ?: [];
        foreach ($currentMatches as $match) {
            $filename = basename($match);
            if ($filename === "{$prefix}.json") {
                $files[0] = $match;
            } elseif (preg_match('/^' . preg_quote($prefix, '/') . '_(\d+)\.json$/', $filename, $m)) {
                $files[(int)$m[1]] = $match;
            }
        }

        // 2. Discover files by hardware serial number: *({sanitizedSn})*.json and *({sanitizedSn})_*.json
        $snPattern = $this->baseDir . DIRECTORY_SEPARATOR . "*({$sanitizedSn})*.json";
        $snMatches = glob($snPattern) ?: [];
        foreach ($snMatches as $match) {
            if (in_array($match, $files)) {
                continue;
            }
            $filename = basename($match);
            if (preg_match('/_(\d+)\.json$/', $filename, $m)) {
                $files[(int)$m[1]] = $match;
            } else {
                if (!isset($files[0])) {
                    $files[0] = $match;
                } else {
                    $files[] = $match;
                }
            }
        }

        ksort($files);

        // 3. Fallback: check if legacy unsegregated or older named files exist (e.g. {deviceName}.json)
        $deviceName = $this->getDeviceNameForSn($deviceSn);
        $legacyPrimary = $this->baseDir . DIRECTORY_SEPARATOR . "{$deviceName}.json";
        if (file_exists($legacyPrimary) && !in_array($legacyPrimary, $files)) {
            $files[] = $legacyPrimary;
        }

        // 4. Content fallback: scan files containing `"device_sn":"<sn>"`
        $allBaseFiles = glob($this->baseDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $snNeedle = '"device_sn":"' . $deviceSn . '"';
        foreach ($allBaseFiles as $f) {
            if (in_array($f, $files)) {
                continue;
            }
            if (file_exists($f) && filesize($f) > 0) {
                $fp = @fopen($f, 'rb');
                if ($fp) {
                    $header = fread($fp, 2048);
                    fclose($fp);
                    if ($header !== false && str_contains($header, $snNeedle)) {
                        $files[] = $f;
                    }
                }
            }
        }

        if (empty($files)) {
            $files[0] = $primary;
        }

        return array_values($files);
    }

    /**
     * Get all command queue files across all devices or for a specific device.
     *
     * @param string|null $deviceSn
     * @return array<string>
     */
    public function getAllCommandFiles(?string $deviceSn = null): array
    {
        if ($deviceSn !== null) {
            return $this->getAllCommandFilesForDevice($deviceSn);
        }

        $pattern = $this->baseDir . DIRECTORY_SEPARATOR . '*.json';
        $files = glob($pattern) ?: [];
        sort($files);
        return $files;
    }

    /**
     * Determine the active file to write new commands for a specific device.
     * If the current file has reached maxSizeBytes (50MB), creates next numbered file: {deviceName}_({serialNumber})_{index}.json
     */
    public function getActiveWriteFileForDevice(string $deviceSn): string
    {
        $allFiles = $this->getAllCommandFilesForDevice($deviceSn);
        $latestFile = end($allFiles);

        if (file_exists($latestFile)) {
            clearstatcache(true, $latestFile);
            if (filesize($latestFile) >= $this->maxSizeBytes) {
                $prefix = $this->getDeviceFilePrefix($deviceSn);
                $nextIndex = $this->getNextFileIndexForPrefix($prefix);
                return $this->baseDir . DIRECTORY_SEPARATOR . "{$prefix}_{$nextIndex}.json";
            }
        }

        return $latestFile;
    }

    /**
     * Get the next incremental file number index for a device prefix.
     */
    public function getNextFileIndexForPrefix(string $prefix): int
    {
        $maxIndex = 0;
        $matches = glob($this->baseDir . DIRECTORY_SEPARATOR . $prefix . '_*.json') ?: [];
        foreach ($matches as $match) {
            $filename = basename($match);
            if (preg_match('/^' . preg_quote($prefix, '/') . '_(\d+)\.json$/', $filename, $m)) {
                $num = (int)$m[1];
                if ($num > $maxIndex) {
                    $maxIndex = $num;
                }
            }
        }

        return $maxIndex + 1;
    }

    /**
     * Backward-compatible helper for getting next file index by device name or prefix.
     */
    protected function getNextFileIndexForDevice(string $deviceName): int
    {
        return $this->getNextFileIndexForPrefix($deviceName);
    }

    /**
     * Get the next unique command ID globally across all queue files.
     */
    public function getNextCommandId(): int
    {
        $allFiles = $this->getAllCommandFiles();
        $maxId = $this->getMaxIdFromFiles($allFiles);
        return $maxId + 1;
    }

    /**
     * Safely open a file with the requested lock type (LOCK_SH or LOCK_EX).
     * Retries with non-blocking LOCK_NB to prevent hanging processes on Windows.
     *
     * @param string $filePath
     * @param string $mode
     * @param int $lockType LOCK_SH or LOCK_EX
     * @param int $maxRetries
     * @return resource|false
     */
    protected function openWithLock(string $filePath, string $mode, int $lockType, int $maxRetries = 25)
    {
        if (!str_contains($mode, 'b')) {
            $mode .= 'b';
        }

        $attempts = 0;
        while ($attempts < $maxRetries) {
            $fp = @fopen($filePath, $mode);
            if ($fp) {
                if (@flock($fp, $lockType | LOCK_NB)) {
                    return $fp;
                }
                fclose($fp);
            }
            $attempts++;
            usleep(20000); // 20ms backoff
        }

        return false;
    }

    /**
     * Extract the highest command ID from an already open file stream.
     * Avoids opening a second handle to the same file which causes self-deadlocks on Windows.
     */
    public function getLastIdFromResource($fp): int
    {
        if (!is_resource($fp)) {
            return 0;
        }

        fseek($fp, 0, SEEK_END);
        $size = ftell($fp);
        if ($size === 0) {
            return 0;
        }

        $readSize = min($size, 8192);
        fseek($fp, max(0, $size - $readSize));
        $chunk = fread($fp, $readSize);
        if ($chunk !== false && preg_match_all('/"id":\s*(\d+)/', $chunk, $matches)) {
            $ids = array_map('intval', $matches[1]);
            return !empty($ids) ? max($ids) : 0;
        }

        if ($size <= 1048576) {
            rewind($fp);
            $content = stream_get_contents($fp);
            if (preg_match_all('/"id":\s*(\d+)/', $content, $matches)) {
                $ids = array_map('intval', $matches[1]);
                return !empty($ids) ? max($ids) : 0;
            }
        }

        return 0;
    }

    /**
     * Extract command records from raw content, supporting JSON array, NDJSON, or a mixture of both.
     *
     * @param string $content
     * @return array<array>
     */
    public function extractRecordsFromRawContent(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        // 1. If it's a valid JSON array directly
        $decoded = json_decode($content, true);
        if (is_array($decoded) && (empty($decoded) || isset($decoded[0]))) {
            return $decoded;
        }

        $records = [];

        // 2. If it contains a bracketed array portion '[...]'
        $firstBracket = strpos($content, '[');
        if ($firstBracket !== false) {
            $lastBracket = strrpos($content, ']');
            if ($lastBracket !== false && $lastBracket > $firstBracket) {
                $arrayChunk = substr($content, $firstBracket, $lastBracket - $firstBracket + 1);
                $arrDecoded = json_decode($arrayChunk, true);
                if (is_array($arrDecoded)) {
                    foreach ($arrDecoded as $item) {
                        if (is_array($item) && isset($item['id'])) {
                            $records[] = $item;
                        }
                    }
                }
                $content = substr($content, 0, $firstBracket) . "\n" . substr($content, $lastBracket + 1);
            }
        }

        // 3. Process remaining lines as NDJSON
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $item = json_decode($line, true);
            if (is_array($item) && isset($item['id'])) {
                $records[] = $item;
            }
        }

        // 4. Deduplicate by ID and sort ascending
        $unique = [];
        foreach ($records as $r) {
            if (isset($r['id'])) {
                if (isset($r['status'])) {
                    $r['status'] = trim($r['status']);
                }
                $unique[$r['id']] = $r;
            }
        }
        ksort($unique);

        return array_values($unique);
    }

    /**
     * Normalize a command storage file to clean JSON Lines (NDJSON) format.
     *
     * @param string $filePath
     * @return bool
     */
    public function normalizeFileIfNeeded(string $filePath): bool
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            return true;
        }

        // Fast check: if file already starts with '{' and ends with '\n', it is already clean NDJSON
        $fpQuick = @fopen($filePath, 'rb');
        if ($fpQuick) {
            clearstatcache(true, $filePath);
            $size = filesize($filePath);
            if ($size === 0) {
                fclose($fpQuick);
                return true;
            }

            $readLen = min($size, 1024);
            $chunk = fread($fpQuick, $readLen);
            if ($chunk !== false) {
                $trimmed = ltrim($chunk);
                if ($trimmed !== '' && $trimmed[0] === '{') {
                    fseek($fpQuick, -1, SEEK_END);
                    $lastChar = fgetc($fpQuick);
                    fclose($fpQuick);
                    if ($lastChar === "\n") {
                        return true;
                    }
                } else {
                    fclose($fpQuick);
                }
            } else {
                fclose($fpQuick);
            }
        }

        $fp = $this->openWithLock($filePath, 'c+b', LOCK_EX);
        if (!$fp) {
            return false;
        }

        try {
            clearstatcache(true, $filePath);
            $size = filesize($filePath);
            if ($size === 0) {
                return true;
            }

            $firstChar = '';
            $readLen = min($size, 1024);
            $chunk = fread($fp, $readLen);
            if ($chunk !== false) {
                $trimmed = ltrim($chunk);
                if ($trimmed !== '') {
                    $firstChar = $trimmed[0];
                }
            }

            if ($firstChar === '') {
                ftruncate($fp, 0);
                return true;
            }

            if ($firstChar === '{') {
                fseek($fp, -1, SEEK_END);
                $lastChar = fgetc($fp);
                if ($lastChar !== "\n") {
                    fseek($fp, 0, SEEK_END);
                    fwrite($fp, "\n");
                    fflush($fp);
                }
                return true;
            }

            rewind($fp);
            $content = stream_get_contents($fp);
            $records = $this->extractRecordsFromRawContent($content);
            unset($content);

            rewind($fp);
            ftruncate($fp, 0);
            foreach ($records as $record) {
                if (isset($record['status'])) {
                    $record['status'] = trim($record['status']);
                }
                fwrite($fp, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            }
            fflush($fp);

            return true;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Extract the highest command ID from a file in O(1) time by inspecting the last 8KB.
     *
     * @param string $filePath
     * @return int
     */
    public function getLastIdFromFile(string $filePath): int
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            return 0;
        }

        $fp = $this->openWithLock($filePath, 'r', LOCK_SH);
        if (!$fp) {
            return 0;
        }

        try {
            clearstatcache(true, $filePath);
            $size = filesize($filePath);
            $readSize = min($size, 8192);
            fseek($fp, max(0, $size - $readSize));
            $chunk = fread($fp, $readSize);
            if ($chunk !== false && preg_match_all('/"id":\s*(\d+)/', $chunk, $matches)) {
                $ids = array_map('intval', $matches[1]);
                return !empty($ids) ? max($ids) : 0;
            }

            if ($size <= 1048576) {
                rewind($fp);
                $content = stream_get_contents($fp);
                if (preg_match_all('/"id":\s*(\d+)/', $content, $matches)) {
                    $ids = array_map('intval', $matches[1]);
                    return !empty($ids) ? max($ids) : 0;
                }
            }

            return 0;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Get the highest command ID across specific files.
     */
    protected function getMaxIdFromFiles(array $files): int
    {
        $max = 0;
        foreach (array_reverse($files) as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $lastId = $this->getLastIdFromFile($file);
            if ($lastId > $max) {
                $max = $lastId;
            }
        }

        return $max;
    }

    /**
     * Parse commands from a file, supporting clean JSON Lines (NDJSON).
     *
     * @param string $filePath
     * @param string|null $deviceSn Optional filter to only parse commands for this device
     * @return array
     */
    protected function parseCommandsFromFile(string $filePath, ?string $deviceSn = null): array
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            return [];
        }

        $this->normalizeFileIfNeeded($filePath);

        $fp = $this->openWithLock($filePath, 'r', LOCK_SH);
        if (!$fp) {
            return [];
        }

        try {
            $commands = [];
            $snNeedle = $deviceSn !== null ? '"device_sn":"' . $deviceSn . '"' : null;

            while (($line = fgets($fp)) !== false) {
                if ($snNeedle !== null && !str_contains($line, $snNeedle)) {
                    continue;
                }
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    if ($deviceSn !== null && ($decoded['device_sn'] ?? '') !== $deviceSn) {
                        continue;
                    }
                    if (isset($decoded['status'])) {
                        $decoded['status'] = trim($decoded['status']);
                    }
                    $commands[] = $decoded;
                }
            }

            return $commands;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Find an active pending or in-flight command record matching the given device and command string.
     */
    public function findPendingCommandRecord(string $deviceSn, string $command): ?array
    {
        $snNeedle = '"device_sn":"' . $deviceSn . '"';

        foreach ($this->getAllCommandFilesForDevice($deviceSn) as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $fp = $this->openWithLock($file, 'r', LOCK_SH);
            if (!$fp) {
                continue;
            }

            try {
                while (($line = fgets($fp)) !== false) {
                    if (str_contains($line, $snNeedle) && (str_contains($line, '"status":"PENDING"') || str_contains($line, '"status":"SENT'))) {
                        $decoded = json_decode(trim($line), true);
                        if ($decoded && ($decoded['device_sn'] ?? '') === $deviceSn &&
                            in_array(trim($decoded['status'] ?? ''), ['PENDING', 'SENT']) &&
                            ($decoded['command'] ?? '') === $command) {
                            return $decoded;
                        }
                    }
                }
            } finally {
                @flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        return null;
    }

    /**
     * Queue a new command into the target device's dedicated queue file.
     *
     * @param string $deviceSn Target device serial number
     * @param string $command ZKTeco command string
     * @return array The created command record
     */
    public function queueCommand(string $deviceSn, string $command): array
    {
        // 1. Check if command is already pending in this device's queue files
        $existing = $this->findPendingCommandRecord($deviceSn, $command);
        if ($existing !== null) {
            return $existing;
        }

        $targetFile = $this->getActiveWriteFileForDevice($deviceSn);
        $this->ensureBaseDirectory();
        $this->normalizeFileIfNeeded($targetFile);

        $fp = $this->openWithLock($targetFile, 'c+', LOCK_EX);
        if (!$fp) {
            return [];
        }

        try {
            clearstatcache(true, $targetFile);
            $curSize = filesize($targetFile);
            if ($curSize >= $this->maxSizeBytes) {
                @flock($fp, LOCK_UN);
                fclose($fp);
                $prefix = $this->getDeviceFilePrefix($deviceSn);
                $nextIndex = $this->getNextFileIndexForPrefix($prefix);
                $targetFile = $this->baseDir . DIRECTORY_SEPARATOR . "{$prefix}_{$nextIndex}.json";
                $this->normalizeFileIfNeeded($targetFile);
                $fp = $this->openWithLock($targetFile, 'c+', LOCK_EX);
                if (!$fp) {
                    return [];
                }
                $curSize = 0;
            }

            // Exclude $targetFile from getMaxIdFromFiles to prevent self-deadlock on Windows.
            // Read max ID for $targetFile directly from $fp!
            $allFiles = $this->getAllCommandFiles();
            $targetRealPath = realpath($targetFile) ?: $targetFile;
            $otherFiles = array_filter($allFiles, function ($f) use ($targetRealPath) {
                $fReal = realpath($f) ?: $f;
                return strcasecmp($fReal, $targetRealPath) !== 0;
            });
            $maxId = $this->getMaxIdFromFiles($otherFiles);
            $idFromFp = $this->getLastIdFromResource($fp);
            if ($idFromFp > $maxId) {
                $maxId = $idFromFp;
            }
            $nextId = $maxId + 1;
            $now = now()->toDateTimeString();

            $record = [
                'id' => $nextId,
                'device_sn' => $deviceSn,
                'command' => $command,
                'status' => 'PENDING',
                'return_code' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            fseek($fp, 0, SEEK_END);
            $endPos = ftell($fp);
            if ($endPos > 0) {
                fseek($fp, $endPos - 1, SEEK_SET);
                $lastChar = fgetc($fp);
                fseek($fp, 0, SEEK_END);
                if ($lastChar !== "\n") {
                    fwrite($fp, "\n");
                }
            }

            fwrite($fp, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            fflush($fp);
            @flock($fp, LOCK_UN);

            return $record;
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * Queue multiple commands in atomic batches separated per device into each device's file.
     *
     * @param array $entries Array of ['device_sn' => string, 'command' => string]
     * @return int Number of newly queued commands
     */
    public function queueCommandsBatch(array $entries): int
    {
        if (empty($entries)) {
            return 0;
        }

        // Group entries by device_sn and deduplicate within batch
        $byDevice = [];
        $seen = [];
        foreach ($entries as $entry) {
            $deviceSn = $entry['device_sn'] ?? null;
            $command = $entry['command'] ?? null;
            if (!$deviceSn || !$command) {
                continue;
            }
            $key = $deviceSn . "\0" . $command;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $byDevice[$deviceSn][] = $entry;
        }

        if (empty($byDevice)) {
            return 0;
        }

        $this->ensureBaseDirectory();
        $nextId = $this->getNextCommandId();
        $totalQueued = 0;
        $now = now()->toDateTimeString();

        foreach ($byDevice as $deviceSn => $devEntries) {
            $targetFile = $this->getActiveWriteFileForDevice($deviceSn);
            $this->normalizeFileIfNeeded($targetFile);

            $fp = $this->openWithLock($targetFile, 'c+', LOCK_EX);
            if (!$fp) {
                continue;
            }

            try {
                clearstatcache(true, $targetFile);
                $curSize = filesize($targetFile);

                // Collect existing pending commands for this device to prevent duplicate queueing
                $pendingMap = [];
                if ($curSize > 0) {
                    rewind($fp);
                    while (($line = fgets($fp)) !== false) {
                        if (str_contains($line, '"status":"PENDING"') || str_contains($line, '"status":"SENT')) {
                            $decoded = json_decode(trim($line), true);
                            if ($decoded && isset($decoded['command']) && in_array(trim($decoded['status'] ?? ''), ['PENDING', 'SENT'])) {
                                $pendingMap[$decoded['command']] = true;
                            }
                        }
                    }
                }

                // Check other rotated files for this device as well
                foreach ($this->getAllCommandFilesForDevice($deviceSn) as $otherFile) {
                    if (strtolower(str_replace('\\', '/', $otherFile)) === strtolower(str_replace('\\', '/', $targetFile)) || !file_exists($otherFile)) {
                        continue;
                    }
                    $ofp = $this->openWithLock($otherFile, 'r', LOCK_SH);
                    if ($ofp) {
                        try {
                            while (($oline = fgets($ofp)) !== false) {
                                if (str_contains($oline, '"status":"PENDING"') || str_contains($oline, '"status":"SENT')) {
                                    $odec = json_decode(trim($oline), true);
                                    if ($odec && isset($odec['command']) && in_array(trim($odec['status'] ?? ''), ['PENDING', 'SENT'])) {
                                        $pendingMap[$odec['command']] = true;
                                    }
                                }
                            }
                        } finally {
                            @flock($ofp, LOCK_UN);
                            fclose($ofp);
                        }
                    }
                }

                fseek($fp, 0, SEEK_END);
                $endPos = ftell($fp);
                if ($endPos > 0) {
                    fseek($fp, $endPos - 1, SEEK_SET);
                    $lastChar = fgetc($fp);
                    fseek($fp, 0, SEEK_END);
                    if ($lastChar !== "\n") {
                        fwrite($fp, "\n");
                    }
                }

                foreach ($devEntries as $entry) {
                    $cmd = $entry['command'];
                    if (isset($pendingMap[$cmd])) {
                        continue;
                    }
                    $pendingMap[$cmd] = true;

                    $record = [
                        'id' => $nextId++,
                        'device_sn' => $deviceSn,
                        'command' => $cmd,
                        'status' => 'PENDING',
                        'return_code' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $jsonLine = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

                    if (ftell($fp) + strlen($jsonLine) >= $this->maxSizeBytes) {
                        fflush($fp);
                        @flock($fp, LOCK_UN);
                        fclose($fp);

                        $prefix = $this->getDeviceFilePrefix($deviceSn);
                        $nextIndex = $this->getNextFileIndexForPrefix($prefix);
                        $targetFile = $this->baseDir . DIRECTORY_SEPARATOR . "{$prefix}_{$nextIndex}.json";
                        $this->normalizeFileIfNeeded($targetFile);
                        $fp = $this->openWithLock($targetFile, 'c+', LOCK_EX);
                        if (!$fp) {
                            break;
                        }
                        fseek($fp, 0, SEEK_END);
                    }

                    fwrite($fp, $jsonLine);
                    $totalQueued++;
                }

                fflush($fp);
                @flock($fp, LOCK_UN);
            } finally {
                if (is_resource($fp)) {
                    fclose($fp);
                }
            }
        }

        return $totalQueued;
    }

    /**
     * Get pending commands for a specific device directly from that device's queue file(s).
     *
     * @param string $deviceSn Target device serial number
     * @param int $limit Maximum number of commands to retrieve
     * @return array List of pending command records
     */
    public function getPendingCommands(string $deviceSn, int $limit = 10): array
    {
        $pending = [];
        $snNeedle = '"device_sn":"' . $deviceSn . '"';

        foreach ($this->getAllCommandFilesForDevice($deviceSn) as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $fp = $this->openWithLock($file, 'r', LOCK_SH);
            if (!$fp) {
                continue;
            }

            try {
                while (($line = fgets($fp)) !== false) {
                    if (str_contains($line, $snNeedle) && str_contains($line, '"status":"PENDING"')) {
                        $cmd = json_decode(trim($line), true);
                        if ($cmd && ($cmd['device_sn'] ?? '') === $deviceSn && trim($cmd['status'] ?? '') === 'PENDING') {
                            $cmd['status'] = 'PENDING';
                            $pending[] = $cmd;
                            if (count($pending) >= $limit) {
                                return $pending;
                            }
                        }
                    }
                }
            } finally {
                @flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        return $pending;
    }

    /**
     * Mark dispatched commands as SENT across device queue files.
     *
     * @param array $commandIds Array of command IDs
     * @param string|null $deviceSn Optional device serial number to narrow target file
     */
    public function markCommandsAsSent(array $commandIds, ?string $deviceSn = null): void
    {
        if (empty($commandIds)) {
            return;
        }

        $lookup = array_flip(array_map('strval', $commandIds));
        $now = now()->toDateTimeString();

        $files = $deviceSn !== null
            ? $this->getAllCommandFilesForDevice($deviceSn)
            : $this->getAllCommandFiles();

        foreach ($files as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            if (empty($lookup)) {
                break;
            }

            $this->normalizeFileIfNeeded($file);

            $fp = $this->openWithLock($file, 'c+b', LOCK_EX);
            if (!$fp) {
                continue;
            }

            $tempStream = fopen('php://temp/maxmemory:1048576', 'w+b');
            if (!$tempStream) {
                @flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }

            try {
                $fileModified = false;

                while (($line = fgets($fp)) !== false) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }

                    $cmd = json_decode($trimmed, true);
                    if ($cmd && isset($cmd['id'])) {
                        $idStr = (string)$cmd['id'];
                        if (isset($lookup[$idStr]) && trim($cmd['status'] ?? '') === 'PENDING') {
                            $cmd['status'] = 'SENT';
                            $cmd['updated_at'] = $now;
                            $fileModified = true;
                            unset($lookup[$idStr]);
                            fwrite($tempStream, json_encode($cmd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                            continue;
                        }
                    }
                    fwrite($tempStream, $trimmed . "\n");
                }

                if ($fileModified) {
                    rewind($fp);
                    ftruncate($fp, 0);
                    rewind($tempStream);
                    stream_copy_to_stream($tempStream, $fp);
                    fflush($fp);
                }
            } finally {
                fclose($tempStream);
                @flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    /**
     * Record device execution acknowledgment (ACK) from /iclock/devicecmd.
     * If all commands in the target device file are SUCCESS, the file is automatically deleted.
     *
     * @param int|string $commandId Command ID
     * @param int $returnCode Return code from device (>= 0 is success)
     * @return bool True if record was found and updated
     */
    public function recordCommandAck(int|string $commandId, int $returnCode): bool
    {
        $updated = false;
        $matchedCmd = null;
        $now = now()->toDateTimeString();
        $status = $returnCode >= 0 ? 'SUCCESS' : 'FAILED';
        $cmdIdStr = (string)$commandId;
        $affectedFile = null;

        foreach ($this->getAllCommandFiles() as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $this->normalizeFileIfNeeded($file);

            $fp = $this->openWithLock($file, 'c+b', LOCK_EX);
            if (!$fp) {
                continue;
            }

            $tempStream = fopen('php://temp/maxmemory:1048576', 'w+b');
            if (!$tempStream) {
                @flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }

            try {
                $fileModified = false;

                while (($line = fgets($fp)) !== false) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }

                    $cmd = json_decode($trimmed, true);
                    if ($cmd && isset($cmd['id']) && (string)$cmd['id'] === $cmdIdStr) {
                        $currentStatus = trim($cmd['status'] ?? '');
                        if (in_array($currentStatus, ['PENDING', 'SENT'])) {
                            $cmd['status'] = $status;
                            $cmd['return_code'] = $returnCode;
                            $cmd['updated_at'] = $now;
                            $fileModified = true;
                            $updated = true;
                            $matchedCmd = $cmd;
                            $affectedFile = $file;
                            fwrite($tempStream, json_encode($cmd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                            continue;
                        }
                    }
                    fwrite($tempStream, $trimmed . "\n");
                }

                if ($fileModified) {
                    rewind($fp);
                    ftruncate($fp, 0);
                    rewind($tempStream);
                    stream_copy_to_stream($tempStream, $fp);
                    fflush($fp);
                }
            } finally {
                fclose($tempStream);
                @flock($fp, LOCK_UN);
                fclose($fp);
            }

            if ($updated) {
                break;
            }
        }

        if ($updated && $matchedCmd) {
            RegistrationLogger::logCommandAck($matchedCmd, $returnCode);

            // Automatically check and delete this file if all commands in it have reached SUCCESS
            if ($affectedFile && file_exists($affectedFile)) {
                if ($this->isFileAllSuccess($affectedFile)) {
                    $this->deleteFileSafely($affectedFile);
                }
            }

            // Also check all files in case other files completed
            if ($status === 'SUCCESS') {
                $this->pruneCompletedFiles();
            }
        }

        return $updated;
    }

    /**
     * Check if a command file contains only SUCCESS commands (and at least 1 command).
     * If it contains any PENDING, SENT, FAILED, or other non-SUCCESS commands, returns false.
     *
     * @param string $filePath
     * @return bool True if all commands in the file are SUCCESS
     */
    public function isFileAllSuccess(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        clearstatcache(true, $filePath);
        if (filesize($filePath) === 0) {
            return true; // Empty files are safe to delete
        }

        $fp = $this->openWithLock($filePath, 'r', LOCK_SH);
        if (!$fp) {
            return false;
        }

        try {
            $hasCommands = false;

            while (($line = fgets($fp)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                if (str_contains($line, '"status":"PENDING"') || str_contains($line, '"status":"SENT') || str_contains($line, '"status":"FAILED"')) {
                    return false;
                }

                $cmd = json_decode($trimmed, true);
                if (!$cmd || !isset($cmd['id'])) {
                    continue;
                }

                $hasCommands = true;
                if (trim($cmd['status'] ?? '') !== 'SUCCESS') {
                    return false;
                }
            }

            return $hasCommands;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Safely delete a file with retry backoff for Windows file locks.
     */
    public function deleteFileSafely(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return true;
        }

        clearstatcache(true, $filePath);
        $deleted = @unlink($filePath);

        if (!$deleted && file_exists($filePath)) {
            usleep(20000); // 20ms backoff
            clearstatcache(true, $filePath);
            $deleted = @unlink($filePath);
        }

        if ($deleted) {
            self::$previousPendingCache = null;
            Log::channel('device_logs')->info(
                'DeviceCommandService :: Automatically deleted completed command file (all commands SUCCESS)',
                ['file' => basename($filePath)]
            );
        }

        return $deleted;
    }

    /**
     * Inspect all command queue files and delete any file where all commands are SUCCESS or empty.
     *
     * @return array<string> List of deleted file paths
     */
    public function pruneCompletedFiles(): array
    {
        $deleted = [];

        foreach ($this->getAllCommandFiles() as $file) {
            if ($this->isFileAllSuccess($file)) {
                if ($this->deleteFileSafely($file)) {
                    $deleted[] = $file;
                }
            }
        }

        return $deleted;
    }

    /**
     * Check if a pending command matching an exact string exists for this device.
     */
    public function hasPendingCommand(string $deviceSn, string $command): bool
    {
        return $this->findPendingCommandRecord($deviceSn, $command) !== null;
    }

    /**
     * Check if any pending DATA USER command exists for a specific PIN on this device.
     */
    public function hasPendingUserCommand(string $deviceSn, int $pin): bool
    {
        $snNeedle = '"device_sn":"' . $deviceSn . '"';
        $userNeedle = "DATA USER PIN={$pin}";

        foreach ($this->getAllCommandFilesForDevice($deviceSn) as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $fp = $this->openWithLock($file, 'r', LOCK_SH);
            if (!$fp) {
                continue;
            }

            try {
                while (($line = fgets($fp)) !== false) {
                    if (str_contains($line, $snNeedle) && (str_contains($line, '"status":"PENDING"') || str_contains($line, '"status":"SENT')) && str_contains($line, $userNeedle)) {
                        $cmd = json_decode(trim($line), true);
                        if ($cmd && ($cmd['device_sn'] ?? '') === $deviceSn &&
                            in_array(trim($cmd['status'] ?? ''), ['PENDING', 'SENT']) &&
                            str_contains($cmd['command'] ?? '', $userNeedle)) {
                            return true;
                        }
                    }
                }
            } finally {
                @flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        return false;
    }

    /**
     * Get all commands across files, optionally filtered by device serial number.
     *
     * @param string|null $deviceSn
     * @return array
     */
    public function getAllCommands(?string $deviceSn = null): array
    {
        $results = [];

        $files = $deviceSn !== null
            ? $this->getAllCommandFilesForDevice($deviceSn)
            : $this->getAllCommandFiles();

        foreach ($files as $file) {
            $commands = $this->parseCommandsFromFile($file, $deviceSn);
            foreach ($commands as $cmd) {
                $results[] = $cmd;
            }
        }

        return $results;
    }

    /**
     * Clear all stored commands across files (or for a specific device).
     *
     * @param string|null $deviceSn
     */
    public function clearCommands(?string $deviceSn = null): void
    {
        $files = $deviceSn !== null
            ? $this->getAllCommandFilesForDevice($deviceSn)
            : $this->getAllCommandFiles();

        foreach ($files as $file) {
            if (file_exists($file)) {
                $this->deleteFileSafely($file);
            }
        }

        self::$previousPendingCache = null;
    }

    /**
     * Automatically migrate any records from legacy device_commands.json into per-device queue files.
     */
    public function migrateLegacyFileIfNeeded(): void
    {
        $legacyPath = storage_path('app/device_commands.json');
        if (!file_exists($legacyPath) || filesize($legacyPath) === 0) {
            return;
        }

        try {
            $content = file_get_contents($legacyPath);
            $records = $this->extractRecordsFromRawContent($content);

            if (!empty($records)) {
                $byDevice = [];
                foreach ($records as $r) {
                    $sn = $r['device_sn'] ?? 'UNKNOWN';
                    $byDevice[$sn][] = $r;
                }

                foreach ($byDevice as $sn => $devRecords) {
                    $devFile = $this->getDeviceQueueFile($sn);
                    $this->ensureBaseDirectory();
                    $fp = $this->openWithLock($devFile, 'c+', LOCK_EX);
                    if ($fp) {
                        fseek($fp, 0, SEEK_END);
                        foreach ($devRecords as $rec) {
                            fwrite($fp, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                        }
                        fflush($fp);
                        @flock($fp, LOCK_UN);
                        fclose($fp);
                    }
                }
            }

            @unlink($legacyPath);
        } catch (\Throwable $e) {
            Log::channel('device_logs')->warning('DeviceCommandService :: Error migrating legacy commands file: ' . $e->getMessage());
        }
    }
}
