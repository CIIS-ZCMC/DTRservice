<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DeviceCommandService
{
    protected string $filePath;
    protected int $maxSizeBytes;

    /**
     * @param string|null $filePath Path to the command storage file. Defaults to storage/app/device_commands.json
     * @param int $maxSizeBytes Maximum size before auto-clearing. Defaults to 50MB (52,428,800 bytes)
     */
    public function __construct(?string $filePath = null, int $maxSizeBytes = 52428800)
    {
        if (app()->runningUnitTests() || config('app.env') === 'testing') {
            $this->filePath = $filePath ?? storage_path('framework/testing/test_device_commands.json');
        } else {
            $this->filePath = $filePath ?? storage_path('app/device_commands.json');
        }
        $this->maxSizeBytes = $maxSizeBytes;
    }

    /**
     * Check if file exceeds the maximum size limit (50MB) and clear it if so.
     */
    public function checkAndRotateSize(): void
    {
        if (file_exists($this->filePath)) {
            clearstatcache(true, $this->filePath);
            if (filesize($this->filePath) >= $this->maxSizeBytes) {
                file_put_contents($this->filePath, json_encode([], JSON_PRETTY_PRINT), LOCK_EX);
                try {
                    Log::channel('device_logs')->info("DeviceCommandService :: device_commands.json exceeded size limit ({$this->maxSizeBytes} bytes) and was cleared.");
                } catch (\Throwable) {
                    // Ignore log errors if container/config is not booted
                }
            }
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
        $newRecord = [];

        $this->withFileLock(function (array &$commands) use ($deviceSn, $command, &$newRecord) {
            $maxId = 0;
            foreach ($commands as $existing) {
                $id = (int)($existing['id'] ?? 0);
                if ($id > $maxId) {
                    $maxId = $id;
                }
                if (isset($existing['device_sn'], $existing['command'], $existing['status']) &&
                    $existing['device_sn'] === $deviceSn &&
                    $existing['command'] === $command &&
                    $existing['status'] === 'PENDING'
                ) {
                    $newRecord = $existing;
                    return;
                }
            }

            $nextId = $maxId + 1;
            $now = now()->toDateTimeString();
            $newRecord = [
                'id' => $nextId,
                'device_sn' => $deviceSn,
                'command' => $command,
                'status' => 'PENDING',
                'return_code' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $commands[] = $newRecord;
        });

        return $newRecord;
    }

    /**
     * Queue multiple commands in a single atomic batch with deduplication and high performance.
     *
     * @param array $entries Array of ['device_sn' => string, 'command' => string]
     * @return int Number of newly queued commands
     */
    public function queueCommandsBatch(array $entries): int
    {
        if (empty($entries)) {
            return 0;
        }

        $queuedCount = 0;

        $this->withFileLock(function (array &$commands) use ($entries, &$queuedCount) {
            $pendingMap = [];
            $maxId = 0;

            foreach ($commands as $cmd) {
                $id = (int)($cmd['id'] ?? 0);
                if ($id > $maxId) {
                    $maxId = $id;
                }
                if (($cmd['status'] ?? '') === 'PENDING' && isset($cmd['device_sn'], $cmd['command'])) {
                    $pendingMap[$cmd['device_sn'] . "\0" . $cmd['command']] = true;
                }
            }

            $nextId = $maxId + 1;
            $now = now()->toDateTimeString();

            foreach ($entries as $entry) {
                $deviceSn = $entry['device_sn'] ?? null;
                $command = $entry['command'] ?? null;

                if (!$deviceSn || !$command) {
                    continue;
                }

                $key = $deviceSn . "\0" . $command;
                if (isset($pendingMap[$key])) {
                    continue;
                }

                $pendingMap[$key] = true;
                $commands[] = [
                    'id' => $nextId++,
                    'device_sn' => $deviceSn,
                    'command' => $command,
                    'status' => 'PENDING',
                    'return_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $queuedCount++;
            }
        });

        return $queuedCount;
    }

    /**
     * Get pending commands for a specific device.
     *
     * @param string $deviceSn Target device serial number
     * @param int $limit Maximum number of commands to retrieve
     * @return array List of pending command records
     */
    public function getPendingCommands(string $deviceSn, int $limit = 10): array
    {
        $pending = [];

        $this->withFileLock(function (array &$commands) use ($deviceSn, $limit, &$pending) {
            $count = 0;
            foreach ($commands as $cmd) {
                if (isset($cmd['device_sn'], $cmd['status']) && 
                    $cmd['device_sn'] === $deviceSn && 
                    $cmd['status'] === 'PENDING'
                ) {
                    $pending[] = $cmd;
                    $count++;
                    if ($count >= $limit) {
                        break;
                    }
                }
            }
        });

        return $pending;
    }

    /**
     * Mark dispatched commands as SENT.
     *
     * @param array $commandIds Array of command IDs
     */
    public function markCommandsAsSent(array $commandIds): void
    {
        if (empty($commandIds)) {
            return;
        }

        $lookup = array_flip(array_map('strval', $commandIds));

        $this->withFileLock(function (array &$commands) use ($lookup) {
            $now = now()->toDateTimeString();
            foreach ($commands as &$cmd) {
                if (isset($cmd['id']) && isset($lookup[(string)$cmd['id']])) {
                    $cmd['status'] = 'SENT';
                    $cmd['updated_at'] = $now;
                }
            }
        });
    }

    /**
     * Record device execution acknowledgment (ACK) from /iclock/devicecmd.
     *
     * @param int|string $commandId Command ID
     * @param int $returnCode Return code from device (>= 0 is success)
     * @return bool True if record was found and updated
     */
    public function recordCommandAck(int|string $commandId, int $returnCode): bool
    {
        $updated = false;

        $this->withFileLock(function (array &$commands) use ($commandId, $returnCode, &$updated) {
            $now = now()->toDateTimeString();
            foreach ($commands as &$cmd) {
                if (isset($cmd['id']) && (string)$cmd['id'] === (string)$commandId) {
                    $cmd['status'] = $returnCode >= 0 ? 'SUCCESS' : 'FAILED';
                    $cmd['return_code'] = $returnCode;
                    $cmd['updated_at'] = $now;
                    $updated = true;
                    break;
                }
            }
        });

        return $updated;
    }

    /**
     * Check if a pending command matching an exact string exists for a device.
     * Useful to prevent queuing duplicate commands.
     *
     * @param string $deviceSn
     * @param string $command
     * @return bool
     */
    public function hasPendingCommand(string $deviceSn, string $command): bool
    {
        $exists = false;

        $this->withFileLock(function (array &$commands) use ($deviceSn, $command, &$exists) {
            foreach ($commands as $cmd) {
                if (isset($cmd['device_sn'], $cmd['status'], $cmd['command']) &&
                    $cmd['device_sn'] === $deviceSn &&
                    $cmd['status'] === 'PENDING' &&
                    $cmd['command'] === $command
                ) {
                    $exists = true;
                    break;
                }
            }
        });

        return $exists;
    }

    /**
     * Check if any pending DATA USER command exists for a specific PIN on a device.
     *
     * @param string $deviceSn
     * @param int $pin
     * @return bool
     */
    public function hasPendingUserCommand(string $deviceSn, int $pin): bool
    {
        $exists = false;

        $this->withFileLock(function (array &$commands) use ($deviceSn, $pin, &$exists) {
            foreach ($commands as $cmd) {
                if (isset($cmd['device_sn'], $cmd['status'], $cmd['command']) &&
                    $cmd['device_sn'] === $deviceSn &&
                    $cmd['status'] === 'PENDING' &&
                    str_contains($cmd['command'], "DATA USER PIN={$pin}")
                ) {
                    $exists = true;
                    break;
                }
            }
        });

        return $exists;
    }

    /**
     * Get all commands, optionally filtered by device serial number.
     *
     * @param string|null $deviceSn
     * @return array
     */
    public function getAllCommands(?string $deviceSn = null): array
    {
        $result = [];

        $this->withFileLock(function (array &$commands) use ($deviceSn, &$result) {
            if ($deviceSn === null) {
                $result = $commands;
            } else {
                $result = array_values(array_filter($commands, function ($cmd) use ($deviceSn) {
                    return isset($cmd['device_sn']) && $cmd['device_sn'] === $deviceSn;
                }));
            }
        });

        return $result;
    }

    /**
     * Clear all stored commands in the file.
     */
    public function clearCommands(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->filePath, json_encode([], JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Execute a callback under an exclusive file lock to read and atomically update commands.
     *
     * @param callable $callback function(array &$commands)
     * @return mixed
     */
    protected function withFileLock(callable $callback)
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Pre-check size limit before opening
        $this->checkAndRotateSize();

        $fp = fopen($this->filePath, 'c+');
        if (!$fp) {
            $empty = [];
            return $callback($empty);
        }

        try {
            if (flock($fp, LOCK_EX)) {
                clearstatcache(true, $this->filePath);
                $size = filesize($this->filePath);
                $commands = [];

                if ($size > 0) {
                    rewind($fp);
                    $content = stream_get_contents($fp);
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $commands = $decoded;
                    }
                }

                $result = $callback($commands);

                ftruncate($fp, 0);
                rewind($fp);
                $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
                if (count($commands) <= 500) {
                    $flags |= JSON_PRETTY_PRINT;
                }
                fwrite($fp, json_encode($commands, $flags));
                fflush($fp);
                flock($fp, LOCK_UN);

                return $result;
            }
        } finally {
            fclose($fp);
        }

        return null;
    }
}
