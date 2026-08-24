<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DeviceCommandService
{
    protected string $filePath;
    protected int $maxSizeBytes;

    /**
     * @param string|null $filePath Path to the command storage file. Defaults to storage/app/device_commands.json
     * @param int $maxSizeBytes Maximum size before auto-clearing. Defaults to 10MB (10,485,760 bytes)
     */
    public function __construct(?string $filePath = null, int $maxSizeBytes = 10485760)
    {
        $this->filePath = $filePath ?? storage_path('app/device_commands.json');
        $this->maxSizeBytes = $maxSizeBytes;
    }

    /**
     * Check if file exceeds the maximum size limit (10MB) and clear it if so.
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
            $nextId = 1;
            if (!empty($commands)) {
                $ids = array_column($commands, 'id');
                $nextId = empty($ids) ? 1 : (int)max($ids) + 1;
            }

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
     * Check if a pending command already exists for a device.
     * Useful to prevent queuing duplicate DATA USER commands.
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
                fwrite($fp, json_encode($commands, JSON_PRETTY_PRINT));
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
