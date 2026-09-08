<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DeviceCommandService
{
    protected string $filePath;
    protected int $maxSizeBytes;
    protected static ?array $previousPendingCache = null;

    /**
     * @param string|null $filePath Path to the command storage file. Defaults to storage/app/device_commands.json
     * @param int $maxSizeBytes Maximum size per file before creating a new numbered file. Defaults to 50MB (52,428,800 bytes)
     */
    public function __construct(?string $filePath = null, int $maxSizeBytes = 52428800)
    {
        if ($filePath !== null) {
            $this->filePath = $filePath;
        } elseif (app()->runningUnitTests() || config('app.env') === 'testing') {
            $this->filePath = storage_path('framework/testing/test_device_commands.json');
        } else {
            $this->filePath = storage_path('app/device_commands.json');
        }

        $this->maxSizeBytes = $maxSizeBytes;
    }

    /**
     * Check if the current active file exceeds maximum size limit (50MB) and rotate to a new numbered file if so.
     */
    public function checkAndRotateSize(): void
    {
        $this->getActiveWriteFile();
    }

    /**
     * Get base directory containing command files.
     */
    protected function getBaseDirectory(): string
    {
        return dirname($this->filePath);
    }

    /**
     * Get base filename without extension.
     */
    protected function getBaseFileName(): string
    {
        $basename = basename($this->filePath);
        return pathinfo($basename, PATHINFO_FILENAME);
    }

    /**
     * Get file extension (including dot).
     */
    protected function getExtension(): string
    {
        $ext = pathinfo($this->filePath, PATHINFO_EXTENSION);
        return $ext ? '.' . $ext : '.json';
    }

    /**
     * Get all command files in chronological order (device_commands.json, device_commands_1.json, device_commands_2.json...).
     *
     * @return array<string>
     */
    public function getAllCommandFiles(): array
    {
        $dir = $this->getBaseDirectory();
        if (!is_dir($dir)) {
            return [$this->filePath];
        }

        $base = $this->getBaseFileName();
        $ext = $this->getExtension();

        $files = [];

        // Main file (index 0)
        if (file_exists($this->filePath)) {
            $files[0] = $this->filePath;
        }

        // Numbered files: {base}_{number}{ext}
        $pattern = $dir . DIRECTORY_SEPARATOR . $base . '_*' . $ext;
        $matches = glob($pattern) ?: [];

        foreach ($matches as $match) {
            $filename = basename($match);
            if (preg_match('/^' . preg_quote($base, '/') . '_(\d+)' . preg_quote($ext, '/') . '$/', $filename, $m)) {
                $num = (int)$m[1];
                $files[$num] = $match;
            }
        }

        ksort($files);

        if (empty($files)) {
            $files[0] = $this->filePath;
        }

        return array_values($files);
    }

    /**
     * Determine the active file to write new commands to.
     * If the current file has reached maxSizeBytes (50MB), it returns the next numbered file.
     */
    public function getActiveWriteFile(): string
    {
        $allFiles = $this->getAllCommandFiles();
        $latestFile = end($allFiles);

        if (file_exists($latestFile)) {
            clearstatcache(true, $latestFile);
            if (filesize($latestFile) >= $this->maxSizeBytes) {
                $nextIndex = $this->getNextFileIndex();
                $dir = $this->getBaseDirectory();
                $base = $this->getBaseFileName();
                $ext = $this->getExtension();
                return $dir . DIRECTORY_SEPARATOR . "{$base}_{$nextIndex}{$ext}";
            }
        }

        return $latestFile;
    }

    /**
     * Get the next incremental file number index.
     */
    protected function getNextFileIndex(): int
    {
        $dir = $this->getBaseDirectory();
        $base = $this->getBaseFileName();
        $ext = $this->getExtension();

        $maxIndex = 0;
        $matches = glob($dir . DIRECTORY_SEPARATOR . $base . '_*' . $ext) ?: [];
        foreach ($matches as $match) {
            $filename = basename($match);
            if (preg_match('/^' . preg_quote($base, '/') . '_(\d+)' . preg_quote($ext, '/') . '$/', $filename, $m)) {
                $num = (int)$m[1];
                if ($num > $maxIndex) {
                    $maxIndex = $num;
                }
            }
        }

        return $maxIndex + 1;
    }

    /**
     * Safely open a file with the requested lock type (LOCK_SH or LOCK_EX).
     * Retries on Windows lock contention.
     *
     * @param string $filePath
     * @param string $mode
     * @param int $lockType LOCK_SH or LOCK_EX
     * @param int $maxRetries
     * @return resource|false
     */
    protected function openWithLock(string $filePath, string $mode, int $lockType, int $maxRetries = 10)
    {
        $attempts = 0;
        while ($attempts < $maxRetries) {
            $fp = @fopen($filePath, $mode);
            if ($fp) {
                if (@flock($fp, $lockType)) {
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

            // Fallback for legacy JSON array or small file
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
        foreach (array_reverse($files) as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $lastId = $this->getLastIdFromFile($file);
            if ($lastId > 0) {
                return $lastId;
            }
        }

        return 0;
    }

    /**
     * Parse commands from a file, supporting both JSON Lines (NDJSON) and legacy JSON array format.
     *
     * @param string $filePath
     * @return array
     */
    protected function parseCommandsFromFile(string $filePath): array
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            return [];
        }

        $fp = $this->openWithLock($filePath, 'r', LOCK_SH);
        if (!$fp) {
            return [];
        }

        try {
            // Peek first non-whitespace character
            $firstChar = '';
            while (($char = fgetc($fp)) !== false) {
                if (!ctype_space($char)) {
                    $firstChar = $char;
                    break;
                }
            }
            rewind($fp);

            if ($firstChar === '[') {
                $content = stream_get_contents($fp);
                $decoded = json_decode($content, true);
                return is_array($decoded) ? $decoded : [];
            }

            $commands = [];
            while (($line = fgets($fp)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
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
     * Queue a new command for a device.
     *
     * @param string $deviceSn Target device serial number
     * @param string $command ZKTeco command string (e.g. DATA USER ... or DATA UPDATE fingertmp ...)
     * @return array The created command record
     */
    public function queueCommand(string $deviceSn, string $command): array
    {
        // 1. Check if command is already pending across all files
        if ($this->hasPendingCommand($deviceSn, $command)) {
            foreach ($this->getAllCommandFiles() as $file) {
                if (!file_exists($file) || filesize($file) === 0) continue;
                $fp = $this->openWithLock($file, 'r', LOCK_SH);
                if (!$fp) continue;
                try {
                    while (($line = fgets($fp)) !== false) {
                        if (str_contains($line, '"status":"PENDING"') && str_contains($line, '"device_sn":"' . $deviceSn . '"')) {
                            $decoded = json_decode($line, true);
                            if ($decoded && ($decoded['device_sn'] ?? '') === $deviceSn && ($decoded['status'] ?? '') === 'PENDING' && ($decoded['command'] ?? '') === $command) {
                                return $decoded;
                            }
                        }
                    }
                } finally {
                    @flock($fp, LOCK_UN);
                    fclose($fp);
                }
            }
        }

        $targetFile = $this->getActiveWriteFile();
        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

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
                $nextIndex = $this->getNextFileIndex();
                $targetFile = $this->getBaseDirectory() . DIRECTORY_SEPARATOR . $this->getBaseFileName() . "_{$nextIndex}" . $this->getExtension();
                $fp = $this->openWithLock($targetFile, 'c+', LOCK_EX);
                if (!$fp) {
                    return [];
                }
                $curSize = 0;
            }

            // Fast max ID extraction from last 8KB
            $maxId = 0;
            if ($curSize > 0) {
                fseek($fp, max(0, $curSize - 8192));
                $chunk = fread($fp, 8192);
                if ($chunk !== false && preg_match_all('/"id":\s*(\d+)/', $chunk, $m)) {
                    $ids = array_map('intval', $m[1]);
                    $maxId = !empty($ids) ? max($ids) : 0;
                }
            }

            if ($maxId === 0) {
                $allFiles = $this->getAllCommandFiles();
                $previousFiles = array_filter($allFiles, function ($f) use ($targetFile) {
                    return strtolower(str_replace('\\', '/', $f)) !== strtolower(str_replace('\\', '/', $targetFile));
                });
                $maxId = $this->getMaxIdFromFiles($previousFiles);
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
     * Queue multiple commands in an atomic batch with deduplication and high performance.
     * Automatically splits into numbered files if file size limit (50MB) is reached.
     *
     * @param array $entries Array of ['device_sn' => string, 'command' => string]
     * @return int Number of newly queued commands
     */
    public function queueCommandsBatch(array $entries): int
    {
        if (empty($entries)) {
            return 0;
        }

        // 1. Deduplicate within the batch in memory
        $uniqueEntries = [];
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
            $uniqueEntries[] = $entry;
        }

        if (empty($uniqueEntries)) {
            return 0;
        }

        $targetFile = $this->getActiveWriteFile();
        $allFiles = $this->getAllCommandFiles();
        $previousFiles = array_filter($allFiles, function ($f) use ($targetFile) {
            return strtolower(str_replace('\\', '/', $f)) !== strtolower(str_replace('\\', '/', $targetFile));
        });

        // 2. Load previous files pending commands once into in-memory cache
        if (self::$previousPendingCache === null && !empty($previousFiles)) {
            self::$previousPendingCache = [];
            foreach ($previousFiles as $file) {
                if (!file_exists($file) || filesize($file) === 0) {
                    continue;
                }
                $pfp = $this->openWithLock($file, 'r', LOCK_SH);
                if (!$pfp) {
                    continue;
                }
                try {
                    while (($line = fgets($pfp)) !== false) {
                        if (str_contains($line, '"status":"PENDING"')) {
                            $decoded = json_decode($line, true);
                            if ($decoded && isset($decoded['device_sn'], $decoded['command']) && ($decoded['status'] ?? '') === 'PENDING') {
                                self::$previousPendingCache[$decoded['device_sn'] . "\0" . $decoded['command']] = true;
                            }
                        }
                    }
                } finally {
                    @flock($pfp, LOCK_UN);
                    fclose($pfp);
                }
            }
        }

        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = $this->openWithLock($targetFile, 'c+', LOCK_EX);
        if (!$fp) {
            return 0;
        }

        try {
            clearstatcache(true, $targetFile);
            $curSize = filesize($targetFile);
            if ($curSize >= $this->maxSizeBytes) {
                @flock($fp, LOCK_UN);
                fclose($fp);
                $nextIndex = $this->getNextFileIndex();
                $targetFile = $this->getBaseDirectory() . DIRECTORY_SEPARATOR . $this->getBaseFileName() . "_{$nextIndex}" . $this->getExtension();
                $fp = $this->openWithLock($targetFile, 'c+', LOCK_EX);
                if (!$fp) {
                    return 0;
                }
                $curSize = 0;
            }

            // 3. Scan open targetFile handle for maxId and active pending items
            $pendingMap = self::$previousPendingCache ?? [];
            $maxId = 0;
            if ($curSize > 0) {
                rewind($fp);
                while (($line = fgets($fp)) !== false) {
                    if (preg_match('/"id":\s*(\d+)/', $line, $m)) {
                        $id = (int)$m[1];
                        if ($id > $maxId) {
                            $maxId = $id;
                        }
                    }
                    if (str_contains($line, '"status":"PENDING"')) {
                        $decoded = json_decode($line, true);
                        if ($decoded && isset($decoded['device_sn'], $decoded['command']) && ($decoded['status'] ?? '') === 'PENDING') {
                            $pendingMap[$decoded['device_sn'] . "\0" . $decoded['command']] = true;
                        }
                    }
                }
            }

            if ($maxId === 0) {
                $maxId = $this->getMaxIdFromFiles($previousFiles);
            }

            $nextId = $maxId + 1;
            $now = now()->toDateTimeString();
            $queuedCount = 0;

            fseek($fp, 0, SEEK_END);
            foreach ($uniqueEntries as $entry) {
                $deviceSn = $entry['device_sn'];
                $command = $entry['command'];
                $key = $deviceSn . "\0" . $command;

                if (isset($pendingMap[$key])) {
                    continue;
                }
                $pendingMap[$key] = true;

                $record = [
                    'id' => $nextId++,
                    'device_sn' => $deviceSn,
                    'command' => $command,
                    'status' => 'PENDING',
                    'return_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $jsonLine = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                $lineLen = strlen($jsonLine);

                // Check size limit: rotate to next file if writing this exceeds 50MB
                if (ftell($fp) + $lineLen >= $this->maxSizeBytes) {
                    fflush($fp);
                    @flock($fp, LOCK_UN);
                    fclose($fp);

                    $nextIndex = $this->getNextFileIndex();
                    $targetFile = $this->getBaseDirectory() . DIRECTORY_SEPARATOR . $this->getBaseFileName() . "_{$nextIndex}" . $this->getExtension();
                    $fp = $this->openWithLock($targetFile, 'c+', LOCK_EX);
                    if (!$fp) {
                        return $queuedCount;
                    }
                    fseek($fp, 0, SEEK_END);
                }

                fwrite($fp, $jsonLine);
                $queuedCount++;
            }

            fflush($fp);
            @flock($fp, LOCK_UN);

            return $queuedCount;
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * Get pending commands for a specific device, checking across all command files in chronological order.
     *
     * @param string $deviceSn Target device serial number
     * @param int $limit Maximum number of commands to retrieve
     * @return array List of pending command records
     */
    public function getPendingCommands(string $deviceSn, int $limit = 10): array
    {
        $pending = [];
        $snNeedle = '"device_sn":"' . $deviceSn . '"';

        foreach ($this->getAllCommandFiles() as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $fp = $this->openWithLock($file, 'r', LOCK_SH);
            if (!$fp) {
                continue;
            }

            try {
                // Check if file is legacy JSON array
                $firstChar = '';
                while (($char = fgetc($fp)) !== false) {
                    if (!ctype_space($char)) {
                        $firstChar = $char;
                        break;
                    }
                }
                rewind($fp);

                if ($firstChar === '[') {
                    $content = stream_get_contents($fp);
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $cmd) {
                            if (($cmd['device_sn'] ?? '') === $deviceSn && ($cmd['status'] ?? '') === 'PENDING') {
                                $pending[] = $cmd;
                                if (count($pending) >= $limit) {
                                    return $pending;
                                }
                            }
                        }
                    }
                    continue;
                }

                // Stream line-by-line
                while (($line = fgets($fp)) !== false) {
                    if (str_contains($line, $snNeedle) && str_contains($line, '"status":"PENDING"')) {
                        $cmd = json_decode($line, true);
                        if ($cmd && ($cmd['device_sn'] ?? '') === $deviceSn && ($cmd['status'] ?? '') === 'PENDING') {
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
     * Mark dispatched commands as SENT across all files where they reside.
     * Updates in-place under exclusive lock without creating any temporary files.
     *
     * @param array $commandIds Array of command IDs
     */
    public function markCommandsAsSent(array $commandIds): void
    {
        if (empty($commandIds)) {
            return;
        }

        $lookup = array_flip(array_map('strval', $commandIds));
        $now = now()->toDateTimeString();

        foreach ($this->getAllCommandFiles() as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $fp = $this->openWithLock($file, 'c+', LOCK_EX);
            if (!$fp) {
                continue;
            }

            try {
                $firstChar = '';
                while (($char = fgetc($fp)) !== false) {
                    if (!ctype_space($char)) {
                        $firstChar = $char;
                        break;
                    }
                }
                rewind($fp);

                $fileModified = false;

                if ($firstChar === '[') {
                    $content = stream_get_contents($fp);
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as &$cmd) {
                            if (isset($cmd['id']) && isset($lookup[(string)$cmd['id']])) {
                                $cmd['status'] = 'SENT';
                                $cmd['updated_at'] = $now;
                                $fileModified = true;
                            }
                        }
                        if ($fileModified) {
                            rewind($fp);
                            ftruncate($fp, 0);
                            fwrite($fp, json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                            fflush($fp);
                        }
                    }
                } else {
                    $remainingLookup = $lookup;
                    while (!feof($fp)) {
                        $lineStart = ftell($fp);
                        $line = fgets($fp);
                        if ($line === false) {
                            break;
                        }

                        if (!str_contains($line, '"status":"PENDING"')) {
                            continue;
                        }

                        foreach ($remainingLookup as $cmdId => $_) {
                            if (str_contains($line, '"id":' . $cmdId . ',') || str_contains($line, '"id":' . $cmdId . '}')) {
                                $pos = strpos($line, '"status":"PENDING"');
                                if ($pos !== false) {
                                    fseek($fp, $lineStart + $pos, SEEK_SET);
                                    fwrite($fp, '"status":"SENT   "');
                                    fseek($fp, $lineStart + strlen($line), SEEK_SET);
                                    unset($remainingLookup[$cmdId]);
                                }
                                break;
                            }
                        }

                        if (empty($remainingLookup)) {
                            break;
                        }
                    }
                    fflush($fp);
                }
            } finally {
                @flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    /**
     * Record device execution acknowledgment (ACK) from /iclock/devicecmd across all files.
     * Updates in-place under exclusive lock without creating any temporary files.
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

        foreach ($this->getAllCommandFiles() as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $fp = $this->openWithLock($file, 'c+', LOCK_EX);
            if (!$fp) {
                continue;
            }

            try {
                $firstChar = '';
                while (($char = fgetc($fp)) !== false) {
                    if (!ctype_space($char)) {
                        $firstChar = $char;
                        break;
                    }
                }
                rewind($fp);

                $fileModified = false;

                if ($firstChar === '[') {
                    $content = stream_get_contents($fp);
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as &$cmd) {
                            if (isset($cmd['id']) && (string)$cmd['id'] === $cmdIdStr) {
                                $cmd['status'] = $status;
                                $cmd['return_code'] = $returnCode;
                                $cmd['updated_at'] = $now;
                                $fileModified = true;
                                $updated = true;
                                $matchedCmd = $cmd;
                            }
                        }
                        if ($fileModified) {
                            rewind($fp);
                            ftruncate($fp, 0);
                            fwrite($fp, json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                            fflush($fp);
                        }
                    }
                } else {
                    $needle1 = '"id":' . $cmdIdStr . ',';
                    $needle2 = '"id":' . $cmdIdStr . '}';

                    while (!feof($fp)) {
                        $lineStart = ftell($fp);
                        $line = fgets($fp);
                        if ($line === false) {
                            break;
                        }

                        if (str_contains($line, $needle1) || str_contains($line, $needle2)) {
                            $trimmed = trim($line);
                            $cmd = json_decode($trimmed, true);
                            if ($cmd && (string)($cmd['id'] ?? '') === $cmdIdStr) {
                                $pos = strpos($line, '"status":"');
                                if ($pos !== false) {
                                    $currentStatusPart = substr($line, $pos, 18);
                                    if ($currentStatusPart === '"status":"PENDING"' || $currentStatusPart === '"status":"SENT   "') {
                                        $paddedStatus = $status === 'SUCCESS' ? '"status":"SUCCESS"' : '"status":"FAILED "';
                                        fseek($fp, $lineStart + $pos, SEEK_SET);
                                        fwrite($fp, $paddedStatus);
                                    }
                                }

                                $posRet = strpos($line, '"return_code":');
                                if ($posRet !== false) {
                                    $retPart = substr($line, $posRet, 18);
                                    if (str_starts_with($retPart, '"return_code":')) {
                                        $paddedRet = sprintf('"return_code":%-4s', $returnCode);
                                        fseek($fp, $lineStart + $posRet, SEEK_SET);
                                        fwrite($fp, $paddedRet);
                                    }
                                }
                                fflush($fp);
                                $updated = true;
                                $cmd['status'] = $status;
                                $cmd['return_code'] = $returnCode;
                                $matchedCmd = $cmd;
                                break;
                            }
                        }
                    }
                }
            } finally {
                @flock($fp, LOCK_UN);
                fclose($fp);
            }

            if ($updated) {
                break; // Found and updated in this file; no need to scan remaining files
            }
        }

        if ($updated && $matchedCmd) {
            \App\Services\RegistrationLogger::logCommandAck($matchedCmd, $returnCode);

            // Automatically clean up any file where all commands are SUCCESS
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
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            return false;
        }

        $fp = $this->openWithLock($filePath, 'r', LOCK_SH);
        if (!$fp) {
            return false;
        }

        try {
            $hasCommands = false;

            // Peek for legacy JSON array
            $firstChar = '';
            while (($char = fgetc($fp)) !== false) {
                if (!ctype_space($char)) {
                    $firstChar = $char;
                    break;
                }
            }
            rewind($fp);

            if ($firstChar === '[') {
                $content = stream_get_contents($fp);
                $decoded = json_decode($content, true);
                if (!is_array($decoded) || empty($decoded)) {
                    return false;
                }
                foreach ($decoded as $cmd) {
                    if (trim($cmd['status'] ?? '') !== 'SUCCESS') {
                        return false;
                    }
                }
                return true;
            }

            // Stream NDJSON line-by-line
            while (($line = fgets($fp)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                // If line contains PENDING or SENT, it's definitely not completed; retain immediately
                if (str_contains($line, '"status":"PENDING"') || str_contains($line, '"status":"SENT   "')) {
                    return false;
                }

                $cmd = json_decode($trimmed, true);
                if (!$cmd || !isset($cmd['id'])) {
                    continue;
                }

                $hasCommands = true;
                if (trim($cmd['status'] ?? '') !== 'SUCCESS') {
                    return false; // Retain file if any command failed or is not SUCCESS
                }
            }

            return $hasCommands;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Automatically inspect all command queue files and delete any file where all commands are SUCCESS.
     * Retains any files that still have PENDING, SENT, or non-SUCCESS commands.
     *
     * @return array<string> List of deleted file paths
     */
    public function pruneCompletedFiles(): array
    {
        $deleted = [];

        foreach ($this->getAllCommandFiles() as $file) {
            if ($this->isFileAllSuccess($file)) {
                $fp = $this->openWithLock($file, 'c+', LOCK_EX);
                if ($fp) {
                    @flock($fp, LOCK_UN);
                    fclose($fp);

                    if (@unlink($file)) {
                        $deleted[] = $file;
                        self::$previousPendingCache = null;

                        \Illuminate\Support\Facades\Log::channel('device_logs')->info(
                            'DeviceCommandService :: Automatically deleted completed command file (all commands SUCCESS)',
                            ['file' => basename($file)]
                        );
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Check if a pending command matching an exact string exists across all files.
     *
     * @param string $deviceSn
     * @param string $command
     * @return bool
     */
    public function hasPendingCommand(string $deviceSn, string $command): bool
    {
        $snNeedle = '"device_sn":"' . $deviceSn . '"';

        foreach ($this->getAllCommandFiles() as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $fp = $this->openWithLock($file, 'r', LOCK_SH);
            if (!$fp) {
                continue;
            }

            try {
                $firstChar = '';
                while (($char = fgetc($fp)) !== false) {
                    if (!ctype_space($char)) {
                        $firstChar = $char;
                        break;
                    }
                }
                rewind($fp);

                if ($firstChar === '[') {
                    $content = stream_get_contents($fp);
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $cmd) {
                            if (($cmd['device_sn'] ?? '') === $deviceSn && 
                                ($cmd['status'] ?? '') === 'PENDING' && 
                                ($cmd['command'] ?? '') === $command) {
                                return true;
                            }
                        }
                    }
                    continue;
                }

                while (($line = fgets($fp)) !== false) {
                    if (str_contains($line, $snNeedle) && str_contains($line, '"status":"PENDING"')) {
                        $cmd = json_decode($line, true);
                        if ($cmd && ($cmd['device_sn'] ?? '') === $deviceSn && 
                            ($cmd['status'] ?? '') === 'PENDING' && 
                            ($cmd['command'] ?? '') === $command) {
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
     * Check if any pending DATA USER command exists for a specific PIN across all files.
     *
     * @param string $deviceSn
     * @param int $pin
     * @return bool
     */
    public function hasPendingUserCommand(string $deviceSn, int $pin): bool
    {
        $snNeedle = '"device_sn":"' . $deviceSn . '"';
        $userNeedle = "DATA USER PIN={$pin}";

        foreach ($this->getAllCommandFiles() as $file) {
            if (!file_exists($file) || filesize($file) === 0) {
                continue;
            }

            $fp = $this->openWithLock($file, 'r', LOCK_SH);
            if (!$fp) {
                continue;
            }

            try {
                $firstChar = '';
                while (($char = fgetc($fp)) !== false) {
                    if (!ctype_space($char)) {
                        $firstChar = $char;
                        break;
                    }
                }
                rewind($fp);

                if ($firstChar === '[') {
                    $content = stream_get_contents($fp);
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $cmd) {
                            if (($cmd['device_sn'] ?? '') === $deviceSn && 
                                ($cmd['status'] ?? '') === 'PENDING' && 
                                str_contains($cmd['command'] ?? '', $userNeedle)) {
                                return true;
                            }
                        }
                    }
                    continue;
                }

                while (($line = fgets($fp)) !== false) {
                    if (str_contains($line, $snNeedle) && str_contains($line, '"status":"PENDING"') && str_contains($line, $userNeedle)) {
                        return true;
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
     * Get all commands across all command files, optionally filtered by device serial number.
     *
     * @param string|null $deviceSn
     * @return array
     */
    public function getAllCommands(?string $deviceSn = null): array
    {
        $results = [];

        foreach ($this->getAllCommandFiles() as $file) {
            $commands = $this->parseCommandsFromFile($file);
            foreach ($commands as $cmd) {
                if ($deviceSn === null || ($cmd['device_sn'] ?? '') === $deviceSn) {
                    $results[] = $cmd;
                }
            }
        }

        return $results;
    }

    /**
     * Clear all stored commands across all files.
     */
    public function clearCommands(): void
    {
        foreach ($this->getAllCommandFiles() as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        $dir = $this->getBaseDirectory();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->filePath, '');
        self::$previousPendingCache = null;
    }
}
