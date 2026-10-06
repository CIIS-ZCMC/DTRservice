<?php

namespace App\Services;

use App\Models\Biometrics;
use App\Models\Devices;
use App\Models\EmployeeProfile;
use App\Models\ExternalEmployees;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class OperationLogger
{
    /**
     * Map of standard ZKTeco operational opcodes to human-readable names.
     */
    public static function getOpcodeDescription(int|string|null $opcode): string
    {
        $code = ($opcode !== null && is_numeric($opcode)) ? (int)$opcode : null;
        if ($code === null) {
            return 'General Operation';
        }

        return match ($code) {
            0 => 'System Status / Normal',
            1 => 'Power On / Device Startup',
            2 => 'Power Off / Shutdown / User Delete',
            3 => 'Alarm / Tamper / Duress Event',
            4 => 'Open Menu / Terminal Access',
            5 => 'Change System Settings',
            6 => 'Modify Date & Time',
            7 => 'Open Door / Access Granted',
            8 => 'Clear Data / Reset Device Logs',
            9 => 'Delete User',
            10 => 'Delete Admin Privilege',
            11 => 'Modify User Privilege',
            12 => 'Enroll User / Template',
            13 => 'Change Password',
            14 => 'Door Sensor / Open Door',
            15 => 'Modify Bell Schedule',
            21 => 'Door Sensor Alarm',
            24 => 'Delete Face Template',
            27 => 'Modify Access Timezone',
            36 => 'Clear Biometrics / Clear Templates',
            71 => 'Clear Users / Batch Delete',
            default => "Device Operation (Opcode {$code})",
        };
    }

    /**
     * Resolve device info by IP or SN, and identify fleet type (HRBLIZ vs UMIS).
     */
    public static function resolveDeviceInfo(?string $ipAddress = null, ?string $deviceSn = null): array
    {
        $device = null;
        if (!empty($deviceSn) && \Illuminate\Support\Facades\Schema::hasTable('devices')) {
            $device = Devices::where('serial_number', $deviceSn)->first();
        }
        if (!$device && !empty($ipAddress) && \Illuminate\Support\Facades\Schema::hasTable('devices')) {
            $device = Devices::where('ip_address', $ipAddress)->first();
        }

        $isHrbliz = $device ? (bool)$device->is_hrbliz : false;
        $fleet = $device ? ($isHrbliz ? 'HRBLIZ' : 'UMIS') : 'UNKNOWN';

        return [
            'device' => $device,
            'name' => $device?->device_name ?? 'Unknown Device',
            'ip' => $device?->ip_address ?? $ipAddress ?? 'Unknown IP',
            'sn' => $device?->serial_number ?? $deviceSn ?? 'Unknown SN',
            'is_hrbliz' => $isHrbliz,
            'fleet' => $fleet,
        ];
    }

    /**
     * Resolve operator name based on device fleet mode.
     */
    public static function resolveOperatorName(int|string|null $pin, bool $isHrbliz = false): string
    {
        if (!$pin || (int)$pin <= 0) {
            return 'System / Device';
        }

        $intPin = (int)$pin;

        // 1. Biometrics masterlist
        if (\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            $bio = Biometrics::findByDevicePin($intPin, $isHrbliz);
            if ($bio?->name) {
                return trim($bio->name);
            }
        }

        // 2. Employee profile
        if (\Illuminate\Support\Facades\Schema::hasTable('employee_profiles')) {
            $profile = EmployeeProfile::where('biometric_id', $intPin)->first();
            if ($profile) {
                $name = $profile->personalInformation?->employeeName() ?? $profile->name();
                if (!empty($name)) {
                    return trim($name);
                }
            }
        }

        // 3. External employee
        if (\Illuminate\Support\Facades\Schema::hasTable('external_employees')) {
            $ext = ExternalEmployees::where('biometric_id', $intPin)->first();
            if ($ext) {
                return trim($ext->getFullNameAttribute());
            }
        }

        return 'Unknown';
    }

    /**
     * Log a device operation log event to dedicated log files.
     */
    public static function logOperation(
        int|string|null $opCode,
        int|string|null $operatorPin,
        ?string $opDateTime,
        array $params = [],
        string $rawLine = '',
        ?string $ipAddress = null,
        ?string $deviceSn = null,
        ?string $table = null
    ): void {
        $deviceInfo = self::resolveDeviceInfo($ipAddress, $deviceSn);
        $opDescription = self::getOpcodeDescription($opCode);
        $operatorName = self::resolveOperatorName($operatorPin, $deviceInfo['is_hrbliz']);
        $timestamp = !empty($opDateTime) && strtotime($opDateTime)
            ? date('Y-m-d H:i:s', strtotime($opDateTime))
            : now()->format('Y-m-d H:i:s');
        $today = date('Y-m-d', strtotime($timestamp));

        $logData = [
            'event' => 'DEVICE_OPERATION_LOG',
            'fleet' => $deviceInfo['fleet'], // 'HRBLIZ' or 'UMIS'
            'is_hrbliz' => $deviceInfo['is_hrbliz'],
            'device_name' => $deviceInfo['name'],
            'device_ip' => $deviceInfo['ip'],
            'device_sn' => $deviceInfo['sn'],
            'opcode' => $opCode,
            'operation' => $opDescription,
            'operator_pin' => (string)($operatorPin ?? '0'),
            'operator_name' => $operatorName,
            'params' => $params,
            'table' => $table,
            'timestamp' => $timestamp,
            'raw_line' => $rawLine,
        ];

        // 1. Write to Monolog operation_logs daily channel
        try {
            Log::channel('operation_logs')->info(
                "Device Operation Log [{$deviceInfo['fleet']}]: {$opDescription} by PIN {$operatorPin} ({$operatorName}) on {$deviceInfo['name']}",
                $logData
            );
        } catch (\Throwable) {
            // Channel fallback
        }

        // 2. Monolog device_logs breadcrumb (clearly marked as OPERATION_LOG, distinct from attendance)
        try {
            Log::channel('device_logs')->info(
                "[OPERATION_LOG] [{$deviceInfo['fleet']}] {$deviceInfo['name']} (SN: {$deviceInfo['sn']}) -> {$opDescription} (Opcode: " . ($opCode ?? 'N/A') . ") by PIN " . ($operatorPin ?? '-') . " ({$operatorName})",
                $logData
            );
        } catch (\Throwable) {
        }

        // 3. Write to human-readable audit text file: storage/logs/operation_logs_YYYY-MM-DD.txt
        self::writeToOperationLogFile($logData, $deviceInfo, $today);
    }

    /**
     * Write operation log entry to daily audit text file.
     */
    protected static function writeToOperationLogFile(array $logData, array $deviceInfo, string $today): void
    {
        try {
            $filePath = storage_path("logs/operation_logs_{$today}.txt");

            if (!file_exists($filePath)) {
                $header = "========================================================================================================================\n"
                    . "  DEVICE OPERATION LOGS AUDIT TRAIL - {$today}\n"
                    . "  Format: [Timestamp] | Fleet | Device (SN, IP) | Opcode: Operation | Operator PIN (Name) | Params | Raw Line\n"
                    . "========================================================================================================================\n\n";
                File::put($filePath, $header);
            }

            $paramStr = empty($logData['params']) ? '[]' : '[' . implode(', ', $logData['params']) . ']';
            $operatorStr = ($logData['operator_pin'] !== '0' && !empty($logData['operator_pin']))
                ? "PIN {$logData['operator_pin']} ({$logData['operator_name']})"
                : "System/Device ({$logData['operator_name']})";

            $line = sprintf(
                "[%s] | [%s] | %-24s (SN:%s, IP:%s) | Op %-2s: %-30s | %-28s | Params:%-12s | Raw: %s\n",
                $logData['timestamp'],
                $deviceInfo['fleet'],
                substr($deviceInfo['name'], 0, 24),
                $deviceInfo['sn'],
                $deviceInfo['ip'],
                $logData['opcode'] ?? '-',
                substr($logData['operation'], 0, 30),
                substr($operatorStr, 0, 28),
                $paramStr,
                substr($logData['raw_line'], 0, 100)
            );

            File::append($filePath, $line);
        } catch (\Throwable $e) {
            Log::channel('device_logs')->error("Failed to write operation log file: {$e->getMessage()}");
        }
    }
}
