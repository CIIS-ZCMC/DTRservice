<?php

namespace App\Services;

use App\Models\Devices;
use Illuminate\Support\Facades\Log;

class BiometricSyncService
{
    public function __construct(
        protected ?DeviceCommandService $commandService = null
    ) {
        $this->commandService = $commandService ?? app(DeviceCommandService::class);
    }

    /**
     * Broadcast new/updated user profile data to all other registered active devices.
     *
     * @param string|null $sourceSn Serial number of the enrolling device
     * @param array $userData Parsed user fields (PIN, Name, Pri, Passwd, Card, Grp, etc.)
     * @return int Number of commands queued
     */
    public function syncUserToAll(?string $sourceSn, array $userData): int
    {
        $pin = $userData['PIN'] ?? $userData['Pin'] ?? $userData['pin'] ?? null;
        if (!$pin) {
            return 0;
        }

        $targetDevices = $this->getTargetDevices($sourceSn);
        if ($targetDevices->isEmpty()) {
            return 0;
        }

        // Build key-value segments preserving all attributes sent by the terminal
        $segments = [];
        foreach ($userData as $k => $v) {
            $segments[] = "{$k}={$v}";
        }

        // Ensure critical fields exist if not present in payload
        if (!isset($userData['PIN']) && !isset($userData['Pin'])) {
            array_unshift($segments, "PIN={$pin}");
        }

        $command = "DATA USER " . implode("\t", $segments);
        $queuedCount = 0;

        foreach ($targetDevices as $device) {
            $this->commandService->queueCommand($device->serial_number, $command);
            $queuedCount++;
        }

        Log::channel('device_logs')->info('BiometricSyncService :: Queued USER sync', [
            'pin' => $pin,
            'source_sn' => $sourceSn,
            'target_count' => $queuedCount,
            'command' => $command,
        ]);

        return $queuedCount;
    }

    /**
     * Broadcast biometric template data (fingerprints, face templates, biophoto) to all other active devices.
     * Ensures that the user profile (DATA USER) is queued first if the user does not exist on the target device.
     *
     * @param string|null $sourceSn Serial number of the enrolling device
     * @param string $table Table name (e.g. TEMPLATEV10, FINGERTMP, BIOPHOTO, BIODATA)
     * @param array $bioData Parsed biometric template fields
     * @param bool $ensureUser Whether to ensure DATA USER is queued prior to template
     * @return int Number of commands queued
     */
    public function syncBiometricToAll(?string $sourceSn, string $table, array $bioData, bool $ensureUser = true): int
    {
        $pin = $bioData['PIN'] ?? $bioData['Pin'] ?? $bioData['pin'] ?? $bioData['FP PIN'] ?? null;
        if (!$pin) {
            return 0;
        }

        $targetDevices = $this->getTargetDevices($sourceSn);
        if ($targetDevices->isEmpty()) {
            return 0;
        }

        $queuedCount = 0;

        // 1. Ensure user profile (DATA USER) is queued first so target device has the user record
        if ($ensureUser) {
            $bioModel = null;
            if (\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
                $bioModel = \App\Models\Biometrics::where('biometric_id', $pin)->first();
            }
            $name = $bioModel?->name ?? ($bioData['Name'] ?? 'Unknown');
            $privilege = $bioModel?->privilege ?? ($bioData['Pri'] ?? 0);
            $devicePri = ((int)$privilege === 1 || (int)$privilege === 14) ? 14 : 0;

            $userCommand = "DATA USER PIN={$pin}\tName={$name}\tPri={$devicePri}\tPasswd=\tCard=0\tGrp=1";
            foreach ($targetDevices as $device) {
                // Avoid duplicate consecutive pending user commands
                $hasPendingUser = $this->commandService->hasPendingCommand($device->serial_number, $userCommand);

                if (!$hasPendingUser) {
                    $this->commandService->queueCommand($device->serial_number, $userCommand);
                    $queuedCount++;
                }
            }
        }

        // 2. Queue the biometric template update command
        $tableName = strtolower($table);
        $payloadSegments = [];

        // Standardize fingerprint template payloads
        if (in_array($tableName, ['fingertmp', 'templatev10', 'fp', 'template', 'fingertmpv10'])) {
            $fid = $bioData['FID'] ?? $bioData['Finger_ID'] ?? $bioData['FingerID'] ?? '0';
            $size = $bioData['Size'] ?? $bioData['size'] ?? strlen($bioData['TMP'] ?? $bioData['Template'] ?? '');
            $valid = $bioData['Valid'] ?? $bioData['valid'] ?? '1';
            $tmp = $bioData['TMP'] ?? $bioData['Template'] ?? '';

            if ($tableName === 'templatev10') {
                $payloadSegments = [
                    "PIN={$pin}",
                    "FingerID={$fid}",
                    "Size={$size}",
                    "Valid={$valid}",
                    "Template={$tmp}",
                ];
            } else {
                $tableName = 'fingertmp';
                $payloadSegments = [
                    "PIN={$pin}",
                    "FID={$fid}",
                    "Size={$size}",
                    "Valid={$valid}",
                    "TMP={$tmp}",
                ];
            }
        } else {
            foreach ($bioData as $k => $v) {
                if ($k === 'type' || str_starts_with($k, '_') || str_contains($k, ' ')) {
                    continue;
                }
                $payloadSegments[] = "{$k}={$v}";
            }
            if (!isset($bioData['PIN']) && !isset($bioData['Pin'])) {
                array_unshift($payloadSegments, "PIN={$pin}");
            }
        }

        $payload = implode("\t", $payloadSegments);
        $command = "DATA UPDATE {$tableName}\t{$payload}";

        foreach ($targetDevices as $device) {
            $this->commandService->queueCommand($device->serial_number, $command);
            $queuedCount++;
        }

        Log::channel('device_logs')->info('BiometricSyncService :: Queued BIOMETRIC sync', [
            'table' => $tableName,
            'pin' => $pin,
            'source_sn' => $sourceSn,
            'target_count' => $queuedCount,
            'command' => $command,
        ]);

        return $queuedCount;
    }

    /**
     * Sync a complete user profile and all their enrolled biometric templates to a specific device.
     * Use this to provision an employee onto a specific terminal where they do not yet exist.
     *
     * @param string $deviceSn Target device serial number
     * @param int $pin Biometric ID / PIN
     * @return int Number of commands queued
     */
    public function syncUserAndTemplatesToDevice(string $deviceSn, int $pin): int
    {
        $bioModel = \App\Models\Biometrics::where('biometric_id', $pin)->first();
        if (!$bioModel) {
            return 0;
        }

        $name = $bioModel->name ?? 'Unknown';
        $privilege = $bioModel->privilege ?? 0;
        $devicePri = ((int)$privilege === 1 || (int)$privilege === 14) ? 14 : 0;
        $queuedCount = 0;

        // 1. Create or ensure user profile exists on the device
        $this->commandService->queueCommand(
            $deviceSn,
            "DATA USER PIN={$pin}\tName={$name}\tPri={$devicePri}\tPasswd=\tCard=0\tGrp=1"
        );
        $queuedCount++;

        // 2. Queue all enrolled fingerprint templates for this user
        if (!empty($bioModel->biometric) && $bioModel->biometric !== 'NOT_YET_REGISTERED') {
            $templates = json_decode($bioModel->biometric, true);
            if (is_array($templates)) {
                foreach ($templates as $t) {
                    $fid = $t['Finger_ID'] ?? $t['FID'] ?? '0';
                    $size = $t['Size'] ?? strlen($t['Template'] ?? '');
                    $valid = $t['Valid'] ?? '1';
                    $tmp = $t['Template'] ?? $t['TMP'] ?? '';

                    $this->commandService->queueCommand(
                        $deviceSn,
                        "DATA UPDATE fingertmp\tPIN={$pin}\tFID={$fid}\tSize={$size}\tValid={$valid}\tTMP={$tmp}"
                    );
                    $queuedCount++;
                }
            }
        }

        Log::channel('device_logs')->info('BiometricSyncService :: Provisioned user & all templates to device', [
            'device_sn' => $deviceSn,
            'pin' => $pin,
            'commands_count' => $queuedCount,
        ]);

        return $queuedCount;
    }

    /**
     * Sync a complete user profile and all their enrolled biometric templates to all active registered devices.
     *
     * @param string|null $sourceSn Serial number of the source device (optional)
     * @param int $pin Biometric ID / PIN
     * @return int Number of commands queued
     */
    public function syncUserAndTemplatesToAll(?string $sourceSn, int $pin): int
    {
        $targetDevices = $this->getTargetDevices($sourceSn);
        $totalQueued = 0;

        foreach ($targetDevices as $device) {
            $totalQueued += $this->syncUserAndTemplatesToDevice($device->serial_number, $pin);
        }

        return $totalQueued;
    }

    /**
     * Queue user deletion command to all other active devices.
     *
     * @param string|null $sourceSn
     * @param string $pin
     * @return int
     */
    public function deleteUserFromAll(?string $sourceSn, string $pin): int
    {
        $targetDevices = $this->getTargetDevices($sourceSn);
        if ($targetDevices->isEmpty()) {
            return 0;
        }

        $command = "DATA DELETE USER PIN={$pin}";
        $queuedCount = 0;

        foreach ($targetDevices as $device) {
            $this->commandService->queueCommand($device->serial_number, $command);
            $queuedCount++;
        }

        Log::channel('device_logs')->info('BiometricSyncService :: Queued USER DELETE', [
            'pin' => $pin,
            'source_sn' => $sourceSn,
            'target_count' => $queuedCount,
        ]);

        return $queuedCount;
    }

    /**
     * Delete a specific fingerprint template across all active devices.
     *
     * @param string|null $sourceSn
     * @param int $pin Biometric ID / PIN
     * @param int|string $fingerId Finger ID (0-9)
     * @return int
     */
    public function deleteFingerprintFromAll(?string $sourceSn, int $pin, int|string $fingerId): int
    {
        $targetDevices = $this->getTargetDevices($sourceSn);
        if ($targetDevices->isEmpty()) {
            return 0;
        }

        $command = "DATA DELETE FINGERTMP\tPIN={$pin}\tFID={$fingerId}";
        $queuedCount = 0;

        foreach ($targetDevices as $device) {
            $this->commandService->queueCommand($device->serial_number, $command);
            $queuedCount++;
        }

        Log::channel('device_logs')->info('BiometricSyncService :: Queued FINGERPRINT DELETE', [
            'pin' => $pin,
            'finger_id' => $fingerId,
            'source_sn' => $sourceSn,
            'target_count' => $queuedCount,
        ]);

        return $queuedCount;
    }

    /**
     * Get all active registered devices excluding the source device.
     */
    protected function getTargetDevices(?string $sourceSn)
    {
        $query = Devices::where('is_active', 1)
            ->whereNotNull('serial_number')
            ->where('serial_number', '!=', '');

        if (!empty($sourceSn)) {
            $query->where('serial_number', '!=', $sourceSn);
        }

        return $query->get();
    }
}
