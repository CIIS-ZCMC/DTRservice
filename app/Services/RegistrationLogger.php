<?php

namespace App\Services;

use App\Models\Devices;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class RegistrationLogger
{
    /**
     * Log a verified fingerprint registration event.
     *
     * @param int $biometricId
     * @param string|null $name
     * @param int|string $fingerId
     * @param int|string $size
     * @param int|string $valid
     * @param string $action ('NEW_REGISTRATION', 'REPLACED_NOT_YET_REGISTERED', 'APPENDED_FINGER', 'UPDATED_FINGER')
     * @param array $previousFids
     * @param array $currentFids
     * @param string|null $ipAddress
     * @param string|null $deviceSn
     * @param int $syncedDevicesCount
     * @return void
     */
    public static function logFingerprintRegistration(
        int $biometricId,
        ?string $name,
        int|string $fingerId,
        int|string $size,
        int|string $valid,
        string $action,
        array $previousFids = [],
        array $currentFids = [],
        ?string $ipAddress = null,
        ?string $deviceSn = null,
        int $syncedDevicesCount = 0
    ): void {
        $deviceInfo = self::resolveDeviceInfo($ipAddress, $deviceSn);
        $timestamp = now()->format('Y-m-d H:i:s');
        $prevStr = empty($previousFids) ? 'NONE' : '[' . implode(', ', $previousFids) . ']';
        $currStr = '[' . implode(', ', $currentFids) . ']';

        $logData = [
            'event' => 'FINGERPRINT_REGISTRATION_VERIFIED',
            'biometric_id' => $biometricId,
            'name' => $name ?? 'Unknown',
            'finger_id' => (string)$fingerId,
            'size' => (string)$size,
            'valid' => (string)$valid,
            'action' => $action,
            'previous_fids' => $previousFids,
            'current_fids' => $currentFids,
            'total_enrolled_fingers' => count($currentFids),
            'device_name' => $deviceInfo['name'],
            'device_ip' => $deviceInfo['ip'],
            'device_sn' => $deviceInfo['sn'],
            'synced_devices_count' => $syncedDevicesCount,
            'status' => 'VERIFIED_AND_SAVED',
            'timestamp' => $timestamp,
        ];

        // 1. Monolog registration channel
        try {
            Log::channel('registration_logs')->info("Registration Verified: Biometric ID {$biometricId} ({$name})", $logData);
        } catch (\Throwable $e) {
            Log::channel('device_logs')->info("Registration Verified: Biometric ID {$biometricId} ({$name})", $logData);
        }

        // 2. Human-readable audit text log file (storage/logs/registration_verified_YYYY-MM-DD.txt)
        self::writeToRegistrationFile($logData, $prevStr, $currStr, $deviceInfo);
    }

    /**
     * Log a verified user profile registration push.
     */
    public static function logUserRegistration(
        int|string $pin,
        ?string $name,
        array $userData,
        ?string $ipAddress = null,
        ?string $deviceSn = null,
        int $syncedDevicesCount = 0
    ): void {
        $deviceInfo = self::resolveDeviceInfo($ipAddress, $deviceSn);
        $timestamp = now()->format('Y-m-d H:i:s');

        $logData = [
            'event' => 'USER_PROFILE_REGISTRATION_VERIFIED',
            'biometric_id' => $pin,
            'name' => $name ?? ($userData['Name'] ?? 'Unknown'),
            'user_data' => $userData,
            'device_name' => $deviceInfo['name'],
            'device_ip' => $deviceInfo['ip'],
            'device_sn' => $deviceInfo['sn'],
            'synced_devices_count' => $syncedDevicesCount,
            'status' => 'VERIFIED_AND_SAVED',
            'timestamp' => $timestamp,
        ];

        try {
            Log::channel('registration_logs')->info("User Profile Push Verified: PIN {$pin}", $logData);
        } catch (\Throwable $e) {
            Log::channel('device_logs')->info("User Profile Push Verified: PIN {$pin}", $logData);
        }
    }

    /**
     * Log a biometric profile/template push sync event to device.
     */
    public static function logPushSync(
        int|string $biometricId,
        ?string $name,
        string|Devices $device,
        int $commandCount = 0,
        array $details = []
    ): void {
        $deviceSn = $device instanceof Devices ? $device->serial_number : $device;
        $deviceName = $device instanceof Devices ? $device->device_name : null;
        $deviceIp = $device instanceof Devices ? $device->ip_address : null;

        if (!$deviceName || !$deviceIp) {
            $deviceInfo = self::resolveDeviceInfo($deviceIp, $deviceSn);
            $deviceName = $deviceInfo['name'];
            $deviceIp = $deviceInfo['ip'];
        }

        $timestamp = now()->format('Y-m-d H:i:s');

        $logData = [
            'event' => 'BIOMETRIC_PUSH_SYNC',
            'biometric_id' => $biometricId,
            'name' => $name ?? 'Unknown',
            'device_name' => $deviceName,
            'device_ip' => $deviceIp,
            'device_sn' => $deviceSn,
            'commands_queued' => $commandCount,
            'time_pushed' => $timestamp,
            'details' => $details,
        ];

        // 1. Monolog registration & device channels
        try {
            Log::channel('registration_logs')->info("Biometric Push Sync: PIN {$biometricId} ({$name}) -> Device {$deviceName} [{$timestamp}]", $logData);
        } catch (\Throwable $e) {
            Log::channel('device_logs')->info("Biometric Push Sync: PIN {$biometricId} ({$name}) -> Device {$deviceName} [{$timestamp}]", $logData);
        }

        // 2. Audit file storage/logs/sync_pushed_YYYY-MM-DD.txt
        try {
            $today = now()->format('Y-m-d');
            $filePath = storage_path("logs/sync_pushed_{$today}.txt");

            if (!file_exists($filePath)) {
                $header = "========================================================================================\n"
                    . "  BIOMETRIC PUSH TO DEVICE AUDIT LOG - {$today}\n"
                    . "  Format: [Time Pushed] | BiometricID | Name | Device Name (SN, IP) | Commands Queued\n"
                    . "========================================================================================\n\n";
                File::put($filePath, $header);
            }

            $line = sprintf(
                "[%s] PIN=%-6s | Name=%-25s | Device=%-25s | SN=%-16s | IP=%-15s | Commands=%d\n",
                $timestamp,
                $biometricId,
                substr($name ?? 'Unknown', 0, 25),
                substr($deviceName ?? 'Unknown Device', 0, 25),
                $deviceSn,
                $deviceIp,
                $commandCount
            );

            File::append($filePath, $line);
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    /**
     * Log a command ACK confirmation received from a device.
     */
    public static function logCommandAck(array $cmd, int $returnCode): void
    {
        $commandId = $cmd['id'] ?? 'Unknown';
        $deviceSn = $cmd['device_sn'] ?? 'Unknown';
        $rawCommand = $cmd['command'] ?? '';
        $status = $returnCode >= 0 ? 'SUCCESS' : 'FAILED';
        $timestamp = now()->format('Y-m-d H:i:s');

        // Extract PIN, Name, and Command Type summary
        $pin = null;
        if (preg_match('/PIN=(\d+)/i', $rawCommand, $m)) {
            $pin = $m[1];
        }

        $commandType = 'OTHER';
        if (str_contains($rawCommand, 'DATA USER')) {
            $commandType = 'USER_PROFILE';
        } elseif (str_contains($rawCommand, 'DATA UPDATE fingertmp')) {
            preg_match('/FID=(\d+)/i', $rawCommand, $fm);
            $fid = $fm[1] ?? '?';
            $commandType = "FINGERPRINT_UPDATE (FID {$fid})";
        } elseif (str_contains($rawCommand, 'DATA DELETE FINGERTMP')) {
            preg_match('/FID=(\d+)/i', $rawCommand, $fm);
            $fid = $fm[1] ?? '?';
            $commandType = "FINGERPRINT_DELETE (FID {$fid})";
        } elseif (str_contains($rawCommand, 'DATA UPDATE biodata')) {
            $commandType = 'FACE_NIR_UPDATE';
        } elseif (str_contains($rawCommand, 'DATA UPDATE biophoto')) {
            $commandType = 'FACE_PHOTO_UPDATE';
        } elseif (str_contains($rawCommand, 'DATA DELETE USER')) {
            $commandType = 'USER_DELETE';
        }

        $deviceInfo = self::resolveDeviceInfo(null, $deviceSn);

        $name = 'Unknown';
        if ($pin && \Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            $bio = \App\Models\Biometrics::where('biometric_id', $pin)->first();
            if ($bio?->name) {
                $name = $bio->name;
            }
        }

        $logData = [
            'event' => 'COMMAND_EXECUTION_ACK',
            'command_id' => $commandId,
            'status' => $status,
            'return_code' => $returnCode,
            'biometric_id' => $pin,
            'name' => $name,
            'device_name' => $deviceInfo['name'],
            'device_sn' => $deviceInfo['sn'],
            'device_ip' => $deviceInfo['ip'],
            'command_type' => $commandType,
            'raw_command' => substr($rawCommand, 0, 100),
            'timestamp' => $timestamp,
        ];

        // 1. Monolog log channel
        try {
            $msg = "[{$status}] Command #{$commandId} on Device {$deviceInfo['name']} (SN: {$deviceInfo['sn']}) -> {$commandType}" . ($pin ? " for PIN {$pin} ({$name})" : "") . " (Return={$returnCode})";
            if ($status === 'SUCCESS') {
                Log::channel('registration_logs')->info($msg, $logData);
                Log::channel('device_logs')->info($msg, $logData);
            } else {
                Log::channel('registration_logs')->error($msg, $logData);
                Log::channel('device_logs')->error($msg, $logData);
            }
        } catch (\Throwable) {
            // Ignore
        }

        // 2. Audit file storage/logs/sync_ack_YYYY-MM-DD.txt
        try {
            $today = now()->format('Y-m-d');
            $filePath = storage_path("logs/sync_ack_{$today}.txt");

            if (!file_exists($filePath)) {
                $header = "========================================================================================\n"
                    . "  DEVICE COMMAND EXECUTION / SYNC ACKNOWLEDGMENT LOG - {$today}\n"
                    . "  Format: [Timestamp] Status (Return) | ID | PIN | Name | Device Name (SN) | Command Type\n"
                    . "========================================================================================\n\n";
                File::put($filePath, $header);
            }

            $line = sprintf(
                "[%s] [%-7s] (Ret=%-2d) | CmdID=%-5s | PIN=%-6s | Name=%-22s | Device=%-22s (SN:%s) | %s\n",
                $timestamp,
                $status,
                $returnCode,
                $commandId,
                $pin ?? '-',
                substr($name, 0, 22),
                substr($deviceInfo['name'], 0, 22),
                $deviceInfo['sn'],
                $commandType
            );

            File::append($filePath, $line);
        } catch (\Throwable) {
            // Ignore
        }
    }

    /**
     * Log a self-healing auto-restoration event when a device deleted a user or template.
     */
    public static function logAutoRestore(
        int|string $biometricId,
        ?string $name,
        ?string $deviceSn,
        ?string $ipAddress,
        ?int $opCode,
        int $restoredCommandsCount
    ): void {
        $deviceInfo = self::resolveDeviceInfo($ipAddress, $deviceSn);
        $timestamp = now()->format('Y-m-d H:i:s');

        $logData = [
            'event' => 'AUTO_RESTORE_SELF_HEALING',
            'biometric_id' => $biometricId,
            'name' => $name ?? 'Unknown',
            'device_name' => $deviceInfo['name'],
            'device_ip' => $deviceInfo['ip'],
            'device_sn' => $deviceInfo['sn'],
            'trigger_opcode' => $opCode,
            'restored_commands_count' => $restoredCommandsCount,
            'status' => 'AUTO_RESTORED_FROM_DATABASE',
            'timestamp' => $timestamp,
        ];

        try {
            Log::channel('registration_logs')->warning("Self-Healing Auto-Restore: Biometric ID {$biometricId} ({$name}) was deleted on device {$deviceInfo['name']} (OPLOG {$opCode}) -> Restored from DB", $logData);
        } catch (\Throwable $e) {
            Log::channel('device_logs')->warning("Self-Healing Auto-Restore: Biometric ID {$biometricId} ({$name}) was deleted on device {$deviceInfo['name']} (OPLOG {$opCode}) -> Restored from DB", $logData);
        }

        try {
            $today = now()->format('Y-m-d');
            $filePath = storage_path("logs/registration_verified_{$today}.txt");

            if (!file_exists($filePath)) {
                $header = "========================================================================================\n"
                    . "  BIOMETRIC REGISTRATION VERIFICATION AUDIT LOG - {$today}\n"
                    . "========================================================================================\n\n";
                File::put($filePath, $header);
            }

            $line = sprintf(
                "[%s] [AUTO_RESTORE] PIN=%-6s | Name=%-25s | Trigger=OPLOG %-2d | Device=%s (%s, SN:%s) | Status=RESTORED_FROM_DB (%d commands queued)\n",
                $timestamp,
                $biometricId,
                substr($name ?? 'Unknown', 0, 25),
                $opCode ?? 0,
                $deviceInfo['name'],
                $deviceInfo['ip'],
                $deviceInfo['sn'],
                $restoredCommandsCount
            );

            File::append($filePath, $line);
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    private static function resolveDeviceInfo(?string $ipAddress, ?string $deviceSn): array
    {
        $device = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('devices')) {
            if ($deviceSn) {
                $device = Devices::where('serial_number', $deviceSn)->first();
            }
            if (!$device && $ipAddress) {
                $device = Devices::where('ip_address', $ipAddress)->first();
            }
        }

        return [
            'name' => $device?->device_name ?? 'Unknown Device',
            'ip' => $ipAddress ?? $device?->ip_address ?? 'Unknown IP',
            'sn' => $deviceSn ?? $device?->serial_number ?? 'Unknown SN',
        ];
    }

    private static function writeToRegistrationFile(array $data, string $prevStr, string $currStr, array $deviceInfo): void
    {
        try {
            $today = now()->format('Y-m-d');
            $filePath = storage_path("logs/registration_verified_{$today}.txt");

            if (!file_exists($filePath)) {
                $header = "========================================================================================\n"
                    . "  BIOMETRIC REGISTRATION VERIFICATION AUDIT LOG - {$today}\n"
                    . "========================================================================================\n\n";
                File::put($filePath, $header);
            }

            $line = sprintf(
                "[%s] [VERIFIED] PIN=%-6s | Name=%-25s | FID=%-2s | Size=%-5s | Action=%-28s | Before=%-15s | After=%-18s | Total=%-2d | Device=%s (%s, SN:%s) | SyncedTo=%d devices\n",
                $data['timestamp'],
                $data['biometric_id'],
                substr($data['name'], 0, 25),
                $data['finger_id'],
                $data['size'],
                $data['action'],
                $prevStr,
                $currStr,
                $data['total_enrolled_fingers'],
                $deviceInfo['name'],
                $deviceInfo['ip'],
                $deviceInfo['sn'],
                $data['synced_devices_count']
            );

            File::append($filePath, $line);
        } catch (\Throwable $e) {
            // Ignore file write errors
        }
    }
}
