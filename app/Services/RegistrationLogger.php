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
