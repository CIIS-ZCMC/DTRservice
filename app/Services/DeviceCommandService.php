<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DeviceCommandService
{
    protected string $filePath;
    protected int $maxSizeBytes;

    /**
     * @param string|null $filePath Path to the command storage file. Defaults to storage/app/device_commands.sqlite
     * @param int $maxSizeBytes Maximum size before auto-clearing. Defaults to 500MB (524,288,000 bytes)
     */
    public function __construct(?string $filePath = null, int $maxSizeBytes = 524288000)
    {
        if ($filePath !== null) {
            $this->filePath = $filePath;
        } elseif (app()->runningUnitTests() || config('app.env') === 'testing') {
            $this->filePath = storage_path('framework/testing/test_device_commands.sqlite');
        } else {
            $sqlitePath = storage_path('app/device_commands.sqlite');
            $legacyJson = storage_path('app/device_commands.json');

            // Seamless one-time migration from legacy JSON if sqlite does not exist yet
            if (!file_exists($sqlitePath) && file_exists($legacyJson) && filesize($legacyJson) > 0) {
                $this->filePath = $legacyJson;
                $this->migrateLegacyJsonFile();
                if (file_exists($legacyJson)) {
                    @rename($legacyJson, $sqlitePath);
                }
            }
            $this->filePath = $sqlitePath;
        }

        $this->maxSizeBytes = $maxSizeBytes;
    }

    /**
     * Check if storage exceeds maximum size limit and clear it if so.
     */
    public function checkAndRotateSize(): void
    {
        if (file_exists($this->filePath)) {
            clearstatcache(true, $this->filePath);
            if (filesize($this->filePath) >= $this->maxSizeBytes) {
                $this->clearCommands();
                try {
                    Log::channel('device_logs')->info("DeviceCommandService :: {$this->filePath} exceeded size limit ({$this->maxSizeBytes} bytes) and was cleared.");
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
        $this->checkAndRotateSize();

        return $this->withPdo(function (\PDO $pdo) use ($deviceSn, $command) {
            $stmt = $pdo->prepare("SELECT id, device_sn, command, status, return_code, created_at, updated_at FROM device_commands WHERE device_sn = ? AND status = 'PENDING' AND command = ? LIMIT 1");
            $stmt->execute([$deviceSn, $command]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                $existing['id'] = (int)$existing['id'];
                $existing['return_code'] = $existing['return_code'] !== null ? (int)$existing['return_code'] : null;
                return $existing;
            }

            $now = now()->toDateTimeString();
            $insert = $pdo->prepare("INSERT INTO device_commands (device_sn, command, status, return_code, created_at, updated_at) VALUES (?, ?, 'PENDING', NULL, ?, ?)");
            $insert->execute([$deviceSn, $command, $now, $now]);
            $id = (int)$pdo->lastInsertId();

            return [
                'id' => $id,
                'device_sn' => $deviceSn,
                'command' => $command,
                'status' => 'PENDING',
                'return_code' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });
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

        $this->checkAndRotateSize();

        return $this->withPdo(function (\PDO $pdo) use ($entries) {
            $now = now()->toDateTimeString();
            $queuedCount = 0;

            $pdo->beginTransaction();
            try {
                $checkStmt = $pdo->prepare("SELECT 1 FROM device_commands WHERE device_sn = ? AND status = 'PENDING' AND command = ? LIMIT 1");
                $insertStmt = $pdo->prepare("INSERT INTO device_commands (device_sn, command, status, return_code, created_at, updated_at) VALUES (?, ?, 'PENDING', NULL, ?, ?)");

                $seenInBatch = [];

                foreach ($entries as $entry) {
                    $deviceSn = $entry['device_sn'] ?? null;
                    $command = $entry['command'] ?? null;

                    if (!$deviceSn || !$command) {
                        continue;
                    }

                    $key = $deviceSn . "\0" . $command;
                    if (isset($seenInBatch[$key])) {
                        continue;
                    }
                    $seenInBatch[$key] = true;

                    $checkStmt->execute([$deviceSn, $command]);
                    if ($checkStmt->fetchColumn()) {
                        continue;
                    }

                    $insertStmt->execute([$deviceSn, $command, $now, $now]);
                    $queuedCount++;
                }

                $pdo->commit();
            } catch (\Throwable $t) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $t;
            }

            return $queuedCount;
        });
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
        return $this->withPdo(function (\PDO $pdo) use ($deviceSn, $limit) {
            $stmt = $pdo->prepare("SELECT id, device_sn, command, status, return_code, created_at, updated_at FROM device_commands WHERE device_sn = ? AND status = 'PENDING' ORDER BY id ASC LIMIT ?");
            $stmt->bindValue(1, $deviceSn, \PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['return_code'] = $r['return_code'] !== null ? (int)$r['return_code'] : null;
            }

            return $rows;
        });
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

        $this->withPdo(function (\PDO $pdo) use ($commandIds) {
            $now = now()->toDateTimeString();
            $placeholders = implode(',', array_fill(0, count($commandIds), '?'));
            $stmt = $pdo->prepare("UPDATE device_commands SET status = 'SENT', updated_at = ? WHERE id IN ({$placeholders})");
            $params = array_merge([$now], array_values($commandIds));
            $stmt->execute($params);
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
        $matchedCmd = null;

        $this->withPdo(function (\PDO $pdo) use ($commandId, $returnCode, &$updated, &$matchedCmd) {
            $now = now()->toDateTimeString();
            $status = $returnCode >= 0 ? 'SUCCESS' : 'FAILED';

            $stmt = $pdo->prepare("UPDATE device_commands SET status = ?, return_code = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$status, $returnCode, $now, $commandId]);

            if ($stmt->rowCount() > 0) {
                $updated = true;
                $select = $pdo->prepare("SELECT id, device_sn, command, status, return_code, created_at, updated_at FROM device_commands WHERE id = ? LIMIT 1");
                $select->execute([$commandId]);
                $matchedCmd = $select->fetch(\PDO::FETCH_ASSOC);
                if ($matchedCmd) {
                    $matchedCmd['id'] = (int)$matchedCmd['id'];
                    $matchedCmd['return_code'] = (int)$matchedCmd['return_code'];
                }
            }
        });

        if ($updated && $matchedCmd) {
            \App\Services\RegistrationLogger::logCommandAck($matchedCmd, $returnCode);
        }

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
        return $this->withPdo(function (\PDO $pdo) use ($deviceSn, $command) {
            $stmt = $pdo->prepare("SELECT 1 FROM device_commands WHERE device_sn = ? AND status = 'PENDING' AND command = ? LIMIT 1");
            $stmt->execute([$deviceSn, $command]);
            return (bool)$stmt->fetchColumn();
        });
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
        return $this->withPdo(function (\PDO $pdo) use ($deviceSn, $pin) {
            $stmt = $pdo->prepare("SELECT 1 FROM device_commands WHERE device_sn = ? AND status = 'PENDING' AND command LIKE ? LIMIT 1");
            $stmt->execute([$deviceSn, "%DATA USER PIN={$pin}%"]);
            return (bool)$stmt->fetchColumn();
        });
    }

    /**
     * Get all commands, optionally filtered by device serial number.
     *
     * @param string|null $deviceSn
     * @return array
     */
    public function getAllCommands(?string $deviceSn = null): array
    {
        return $this->withPdo(function (\PDO $pdo) use ($deviceSn) {
            if ($deviceSn !== null) {
                $stmt = $pdo->prepare("SELECT id, device_sn, command, status, return_code, created_at, updated_at FROM device_commands WHERE device_sn = ? ORDER BY id ASC");
                $stmt->execute([$deviceSn]);
            } else {
                $stmt = $pdo->query("SELECT id, device_sn, command, status, return_code, created_at, updated_at FROM device_commands ORDER BY id ASC");
            }

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['return_code'] = $r['return_code'] !== null ? (int)$r['return_code'] : null;
            }

            return $rows;
        });
    }

    /**
     * Clear all stored commands.
     */
    public function clearCommands(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($this->filePath)) {
            $this->withPdo(function (\PDO $pdo) {
                $pdo->exec("DELETE FROM device_commands;");
                $pdo->exec("DELETE FROM sqlite_sequence WHERE name='device_commands';");
                $pdo->exec("VACUUM;");
            });
        }
    }

    /**
     * Execute a callback with a lightweight SQLite PDO instance.
     * Automatically handles connection setup, WAL mode, concurrency timeout, and cleanup.
     *
     * @param callable $callback function(\PDO $pdo)
     * @return mixed
     */
    protected function withPdo(callable $callback)
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->ensureSqliteInitialized();

        $pdo = new \PDO("sqlite:{$this->filePath}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA synchronous = NORMAL;");
        $pdo->exec("PRAGMA busy_timeout = 5000;");

        try {
            return $callback($pdo);
        } finally {
            $pdo = null;
        }
    }

    /**
     * Ensure database schema and indexes exist.
     */
    protected function ensureSqliteInitialized(): void
    {
        if (file_exists($this->filePath) && filesize($this->filePath) > 0) {
            $handle = @fopen($this->filePath, 'r');
            if ($handle) {
                $firstBytes = fread($handle, 16);
                fclose($handle);

                if (!str_starts_with($firstBytes, 'SQLite format 3')) {
                    $this->migrateLegacyJsonFile();
                    return;
                }
            }
        }

        $pdo = new \PDO("sqlite:{$this->filePath}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS device_commands (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_sn TEXT NOT NULL,
            command TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'PENDING',
            return_code INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dev_status_id ON device_commands(device_sn, status, id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dev_status_cmd ON device_commands(device_sn, status, command);");
        $pdo = null;
    }

    /**
     * Migrate legacy JSON commands file to SQLite without data loss.
     */
    protected function migrateLegacyJsonFile(): void
    {
        try {
            $content = file_get_contents($this->filePath);
            $decoded = json_decode($content, true);

            @unlink($this->filePath);

            $pdo = new \PDO("sqlite:{$this->filePath}");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE TABLE IF NOT EXISTS device_commands (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_sn TEXT NOT NULL,
                command TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING',
                return_code INTEGER DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dev_status_id ON device_commands(device_sn, status, id);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dev_status_cmd ON device_commands(device_sn, status, command);");

            if (is_array($decoded) && !empty($decoded)) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO device_commands (id, device_sn, command, status, return_code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($decoded as $cmd) {
                    $stmt->execute([
                        $cmd['id'] ?? null,
                        $cmd['device_sn'] ?? '',
                        $cmd['command'] ?? '',
                        $cmd['status'] ?? 'PENDING',
                        isset($cmd['return_code']) ? (int)$cmd['return_code'] : null,
                        $cmd['created_at'] ?? now()->toDateTimeString(),
                        $cmd['updated_at'] ?? now()->toDateTimeString(),
                    ]);
                }
                $pdo->commit();
            }
            $pdo = null;
        } catch (\Throwable) {
            @unlink($this->filePath);
        }
    }
}
