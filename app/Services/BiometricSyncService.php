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
        $pin = ZkPushParser::resolveEmployeePin($userData);
        if (!$pin) {
            $pin = $userData['PIN'] ?? $userData['Pin'] ?? $userData['pin'] ?? $userData['FP PIN'] ?? null;
            if (!$pin) {
                foreach ($userData as $k => $v) {
                    if (str_contains(strtoupper($k), 'PIN')) {
                        $pin = $v;
                        break;
                    }
                }
            }
        }
        if (!$pin) {
            return 0;
        }

        $targetDevices = $this->getTargetDevices($sourceSn);
        if ($targetDevices->isEmpty()) {
            return 0;
        }

        $sourceDevice = (!empty($sourceSn) && \Illuminate\Support\Facades\Schema::hasTable('devices'))
            ? Devices::where('serial_number', $sourceSn)->first()
            : null;
        $isSourceHrbliz = $sourceDevice && (bool)$sourceDevice->is_hrbliz;

        $bioModel = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            $bioModel = \App\Models\Biometrics::findByDevicePin($pin, $isSourceHrbliz);
        }

        $name = $userData['Name'] ?? $userData['name'] ?? null;
        if (!$name) {
            $name = $bioModel?->name;
        }
        $name = $name ?? 'Unknown';

        $pri = $userData['Pri'] ?? $userData['pri'] ?? $userData['Privilege'] ?? 0;
        $devicePri = ((int)$pri === 1 || (int)$pri === 14) ? 14 : 0;
        $passwd = $userData['Passwd'] ?? $userData['Password'] ?? '';
        $card = $userData['Card'] ?? $userData['card'] ?? 0;
        $incomingGrp = $userData['Grp'] ?? $userData['grp'] ?? $userData['Group'] ?? null;
        $incomingTz = $userData['TZ'] ?? $userData['Tz'] ?? $userData['Timezone'] ?? null;

        // In ZCMC DTRService attendance system, all users MUST have Grp=1 and TZ=1 (24/7 all-access).
        // Any incoming Grp not equal to 1 (e.g. 0, 129, unassigned) or TZ not equal to 1
        // (e.g. 0, bitmasks like 0000000100000000, 100000000, empty/null) must be strictly normalized to 1.
        $grp = 1;
        $tz = 1;

        $entries = [];

        foreach ($targetDevices as $device) {
            $targetPin = $device->is_hrbliz
                ? ($bioModel?->hrbliz_biometric_id ?? ($isSourceHrbliz ? $pin : null))
                : ($bioModel?->biometric_id ?? (!$isSourceHrbliz ? $pin : null));

            if (!$targetPin) {
                continue;
            }

            // Output both PIN and PIN2 so that keypad authentication succeeds on all device generations (v9 and v10)
            $command = "DATA USER PIN={$targetPin}\tPIN2={$targetPin}\tName={$name}\tPri={$devicePri}\tPasswd={$passwd}\tCard={$card}\tGrp={$grp}\tTZ={$tz}";
            $entries[] = ['device_sn' => $device->serial_number, 'command' => $command];
        }

        // If the source device reported an explicit invalid group or timezone (e.g. Grp=129, Grp=0, TZ=0, TZ=0000000100000000),
        // queue DATA USER with Grp=1 & TZ=1 back to the source device to fix it on the enrolling device itself!
        $isGrpInvalid = ($incomingGrp !== null && trim((string)$incomingGrp) !== '1');
        $isTzInvalid = ($incomingTz !== null && trim((string)$incomingTz) !== '1');
        $sourceNeedsTimezoneFix = !empty($sourceSn) && ($isGrpInvalid || $isTzInvalid);

        if ($sourceNeedsTimezoneFix) {
            $sourcePin = $isSourceHrbliz
                ? ($bioModel?->hrbliz_biometric_id ?? $pin)
                : ($bioModel?->biometric_id ?? $pin);
            $fixCommand = "DATA USER PIN={$sourcePin}\tPIN2={$sourcePin}\tName={$name}\tPri={$devicePri}\tPasswd={$passwd}\tCard={$card}\tGrp={$grp}\tTZ={$tz}";
            $entries[] = ['device_sn' => $sourceSn, 'command' => $fixCommand];
        }

        $queuedCount = $this->commandService->queueCommandsBatch($entries);

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
        $pin = ZkPushParser::resolveEmployeePin($bioData);
        if (!$pin) {
            $pin = $bioData['PIN'] ?? $bioData['Pin'] ?? $bioData['pin'] ?? $bioData['FP PIN'] ?? null;
        }
        if (!$pin) {
            return 0;
        }

        $targetDevices = $this->getTargetDevices($sourceSn);
        if ($targetDevices->isEmpty()) {
            return 0;
        }

        $entries = [];

        $sourceDevice = (!empty($sourceSn) && \Illuminate\Support\Facades\Schema::hasTable('devices'))
            ? Devices::where('serial_number', $sourceSn)->first()
            : null;
        $isSourceHrbliz = $sourceDevice && (bool)$sourceDevice->is_hrbliz;

        $bioModel = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            $bioModel = \App\Models\Biometrics::findByDevicePin($pin, $isSourceHrbliz);
        }
        $name = $bioModel?->name ?? ($bioData['Name'] ?? 'Unknown');
        $privilege = $bioModel?->privilege ?? ($bioData['Pri'] ?? 0);
        $devicePri = ((int)$privilege === 1 || (int)$privilege === 14) ? 14 : 0;

        // 1. Ensure user profile (DATA USER) is queued first so target device has the user record
        if ($ensureUser) {
            foreach ($targetDevices as $device) {
                $targetPin = $device->is_hrbliz
                    ? ($bioModel?->hrbliz_biometric_id ?? ($isSourceHrbliz ? $pin : null))
                    : ($bioModel?->biometric_id ?? (!$isSourceHrbliz ? $pin : null));

                if (!$targetPin) {
                    continue;
                }

                // Avoid duplicate consecutive pending user commands
                $hasPendingUser = $this->commandService->hasPendingUserCommand($device->serial_number, (int)$targetPin);

                if (!$hasPendingUser) {
                    $userCommand = "DATA USER PIN={$targetPin}\tPIN2={$targetPin}\tName={$name}\tPri={$devicePri}\tPasswd=\tCard=0\tGrp=1\tTZ=1";
                    $entries[] = ['device_sn' => $device->serial_number, 'command' => $userCommand];
                }
            }
        }

        // 2. Queue the biometric template update command
        $tableName = strtolower($table);
        $isFingerprint = in_array($tableName, ['fingertmp', 'templatev10', 'fp', 'template', 'fingertmpv10', 'templatev9']);
        $templateAlgo = null;

        if ($isFingerprint) {
            $tableName = 'fingertmp';
            $fid = $bioData['FID'] ?? $bioData['Finger_ID'] ?? $bioData['FingerID'] ?? '0';
            $size = $bioData['Size'] ?? $bioData['size'] ?? strlen($bioData['TMP'] ?? $bioData['Template'] ?? '');
            $valid = $bioData['Valid'] ?? $bioData['valid'] ?? '1';
            $tmp = $bioData['TMP'] ?? $bioData['Template'] ?? '';
            $templateAlgo = ($sourceDevice && $sourceDevice->fp_version === 'v9')
                ? 'v9'
                : \App\Models\Biometrics::getTemplateAlgorithm($tmp);
        }

        foreach ($targetDevices as $device) {
            $targetPin = $device->is_hrbliz
                ? ($bioModel?->hrbliz_biometric_id ?? ($isSourceHrbliz ? $pin : null))
                : ($bioModel?->biometric_id ?? (!$isSourceHrbliz ? $pin : null));

            if (!$targetPin) {
                continue;
            }

            // For fingerprints, skip devices whose algorithm does not match the template algorithm
            if ($isFingerprint && $templateAlgo) {
                $devAlgo = $device->getFingerprintAlgorithm();
                if ($devAlgo !== $templateAlgo) {
                    Log::channel('device_logs')->debug("BiometricSyncService :: Skipping template sync to {$device->serial_number} due to algorithm mismatch (template={$templateAlgo}, device={$devAlgo})");
                    continue;
                }
            }

            $payloadSegments = [];
            if ($isFingerprint) {
                $payloadSegments = [
                    "PIN={$targetPin}",
                    "FID={$fid}",
                    "Size={$size}",
                    "Valid={$valid}",
                    "TMP={$tmp}",
                ];
            } else {
                foreach ($bioData as $k => $v) {
                    if ($k === 'type' || str_starts_with($k, '_') || str_contains($k, ' ') || strtoupper($k) === 'PIN') {
                        continue;
                    }
                    $payloadSegments[] = "{$k}={$v}";
                }
                array_unshift($payloadSegments, "PIN={$targetPin}");
            }

            $payload = implode("\t", $payloadSegments);
            $command = "DATA UPDATE {$tableName}\t{$payload}";
            $entries[] = ['device_sn' => $device->serial_number, 'command' => $command];
        }

        $queuedCount = $this->commandService->queueCommandsBatch($entries);

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
     * Generate all ZKTeco provision commands (USER + FINGERTMP + BIODATA + BIOPHOTO) for a user model.
     *
     * @param \App\Models\Biometrics $bioModel
     * @param bool $cleanUnusedFingers Whether to delete unenrolled finger slots (0-9)
     * @param \App\Models\Devices|null $targetDevice Target device (used to match fingerprint algorithm v10 vs v9)
     * @return array Array of command strings
     */
    public function generateUserProvisionCommands(
        \App\Models\Biometrics $bioModel,
        bool $cleanUnusedFingers = true,
        ?\App\Models\Devices $targetDevice = null
    ): array {
        $isHrbliz = $targetDevice && (bool)$targetDevice->is_hrbliz;
        $pin = $isHrbliz 
            ? ($bioModel->hrbliz_biometric_id ? (int)$bioModel->hrbliz_biometric_id : null)
            : (int)$bioModel->biometric_id;

        if ($pin === null || $pin <= 0) {
            // Cannot provision user to this terminal without a valid assigned PIN for device fleet mode
            return [];
        }

        $name = $bioModel->name ?? 'Unknown';
        $privilege = $bioModel->privilege ?? 0;
        $devicePri = ((int)$privilege === 1 || (int)$privilege === 14) ? 14 : 0;
        $commands = [];

        // 1. Create or ensure user profile exists on device (TZ=1 for 24/7 all-access, Grp=1, dual PIN for v9/v10 cross-compatibility)
        $commands[] = "DATA USER PIN={$pin}\tPIN2={$pin}\tName={$name}\tPri={$devicePri}\tPasswd=\tCard=0\tGrp=1\tTZ=1";

        // 2. Fingerprints
        $enrolledFids = [];
        $templates = [];

        $targetAlgo = $targetDevice ? $targetDevice->getFingerprintAlgorithm() : null;

        if (!empty($bioModel->biometric) && $bioModel->biometric !== 'NOT_YET_REGISTERED') {
            if ($targetAlgo) {
                $templates = $bioModel->getTemplatesForAlgorithm($targetAlgo);
            } else {
                $parsed = is_array($bioModel->biometric) ? $bioModel->biometric : json_decode($bioModel->biometric, true);
                if (is_string($parsed)) {
                    $parsed = json_decode($parsed, true);
                }
                if (is_array($parsed)) {
                    $templates = $parsed;
                }
            }

            foreach ($templates as $t) {
                $fid = (int)($t['Finger_ID'] ?? $t['FID'] ?? 0);
                $enrolledFids[$fid] = true;
            }
        }

        // Clean out any finger slots (0-9) that are not enrolled in DB
        // When $cleanUnusedFingers is true and the user has matching fingerprint records
        if ($cleanUnusedFingers && !empty($enrolledFids)) {
            for ($slot = 0; $slot <= 9; $slot++) {
                if (!isset($enrolledFids[$slot])) {
                    $commands[] = "DATA DELETE FINGERTMP\tPIN={$pin}\tFID={$slot}";
                }
            }
        }

        // Update/overwrite enrolled fingers
        foreach ($templates as $t) {
            $fid = $t['Finger_ID'] ?? $t['FID'] ?? '0';
            $size = $t['Size'] ?? strlen($t['Template'] ?? '');
            $valid = $t['Valid'] ?? '1';
            $tmp = $t['Template'] ?? $t['TMP'] ?? '';
            $commands[] = "DATA UPDATE fingertmp\tPIN={$pin}\tFID={$fid}\tSize={$size}\tValid={$valid}\tTMP={$tmp}";
        }

        // 3. NIR Face (table BIODATA Type 9)
        if (!empty($bioModel->face)) {
            $faceData = is_array($bioModel->face) ? $bioModel->face : json_decode($bioModel->face, true);
            if (is_array($faceData)) {
                $segments = [];
                foreach ($faceData as $k => $v) {
                    if ($k === 'type' || str_starts_with($k, '_') || str_contains($k, ' ')) continue;
                    $segments[] = "{$k}={$v}";
                }
                if (!isset($faceData['PIN']) && !isset($faceData['Pin'])) {
                    array_unshift($segments, "PIN={$pin}");
                }
                $payload = implode("\t", $segments);
                $commands[] = "DATA UPDATE biodata\t{$payload}";
            }
        }

        // 4. Visible Light BioPhoto (table BIOPHOTO)
        if (!empty($bioModel->biophoto)) {
            $photoData = is_array($bioModel->biophoto) ? $bioModel->biophoto : json_decode($bioModel->biophoto, true);
            if (is_array($photoData)) {
                $fileName = $photoData['FileName'] ?? "{$pin}.jpg";
                $size = $photoData['Size'] ?? strlen($photoData['Content'] ?? '');
                $content = $photoData['Content'] ?? '';
                $commands[] = "DATA UPDATE biophoto\tPIN={$pin}\tFileName={$fileName}\tSize={$size}\tContent={$content}";
            }
        }

        return $commands;
    }

    /**
     * Sync a complete user profile and all their enrolled biometric templates to a specific device.
     * Use this to provision an employee onto a specific terminal where they do not yet exist.
     *
     * @param string $deviceSn Target device serial number
     * @param int|\App\Models\Biometrics $pin Biometric ID / PIN or Model instance
     * @param bool $cleanUnusedFingers Whether to delete unenrolled finger slots (0-9)
     * @return int Number of commands queued
     */
    public function syncUserAndTemplatesToDevice(string $deviceSn, int|\App\Models\Biometrics $pin, bool $cleanUnusedFingers = false): int
    {
        $bioModel = $pin instanceof \App\Models\Biometrics
            ? $pin
            : \App\Models\Biometrics::where('biometric_id', $pin)->first();

        if (!$bioModel) {
            return 0;
        }

        $targetDevice = Devices::where('serial_number', $deviceSn)->first();
        $commandStrings = $this->generateUserProvisionCommands($bioModel, $cleanUnusedFingers, $targetDevice);
        if (empty($commandStrings)) {
            return 0;
        }

        $entries = [];
        foreach ($commandStrings as $cmd) {
            $entries[] = [
                'device_sn' => $deviceSn,
                'command' => $cmd,
            ];
        }

        $queuedCount = $this->commandService->queueCommandsBatch($entries);

        \App\Services\RegistrationLogger::logPushSync(
            $bioModel->biometric_id,
            $bioModel->name,
            $deviceSn,
            count($commandStrings)
        );

        Log::channel('device_logs')->info('BiometricSyncService :: Provisioned user & all templates to device', [
            'device_sn' => $deviceSn,
            'pin' => $bioModel->biometric_id,
            'commands_count' => count($commandStrings),
            'newly_queued' => $queuedCount,
        ]);

        return count($commandStrings);
    }

    /**
     * Sync a complete user profile and all their enrolled biometric templates to all active registered devices.
     *
     * @param string|null $sourceSn Serial number of the source device (optional)
     * @param int $pin Biometric ID / PIN
     * @param bool $cleanUnusedFingers Whether to delete unenrolled finger slots (0-9)
     * @return int Number of commands queued
     */
    public function syncUserAndTemplatesToAll(?string $sourceSn, int $pin, bool $cleanUnusedFingers = false): int
    {
        $targetDevices = $this->getTargetDevices($sourceSn);
        $totalQueued = 0;

        foreach ($targetDevices as $device) {
            $totalQueued += $this->syncUserAndTemplatesToDevice($device->serial_number, $pin, $cleanUnusedFingers);
        }

        return $totalQueued;
    }

    /**
     * Sync all registered users and their enrolled templates from the database to a specific device.
     *
     * @param string $deviceSn
     * @param bool $cleanUnusedFingers Whether to delete unenrolled finger slots (0-9)
     * @return int Total commands queued
     */
    public function syncAllUsersToDevice(string $deviceSn, bool $cleanUnusedFingers = true): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            return 0;
        }

        $allUsers = \App\Models\Biometrics::whereNotNull('biometric_id')->get();
        $totalQueued = 0;

        foreach ($allUsers as $user) {
            $totalQueued += $this->syncUserAndTemplatesToDevice($deviceSn, (int)$user->biometric_id, $cleanUnusedFingers);
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
        $entries = [];

        foreach ($targetDevices as $device) {
            $entries[] = ['device_sn' => $device->serial_number, 'command' => $command];
        }

        $queuedCount = $this->commandService->queueCommandsBatch($entries);

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
        $entries = [];

        foreach ($targetDevices as $device) {
            $entries[] = ['device_sn' => $device->serial_number, 'command' => $command];
        }

        $queuedCount = $this->commandService->queueCommandsBatch($entries);

        Log::channel('device_logs')->info('BiometricSyncService :: Queued FINGERPRINT DELETE', [
            'pin' => $pin,
            'finger_id' => $fingerId,
            'source_sn' => $sourceSn,
            'target_count' => $queuedCount,
        ]);

        return $queuedCount;
    }

    /**
     * Legacy ZKTeco timezone command.
     * Note: Standalone ZKTeco ADMS attendance terminals reject dynamic 'timezone' table updates with -1004.
     * 24/7 attendance access is enforced via 'Grp=1\tTZ=1' in the 'DATA USER' payload.
     */
    public function getTimezone24x7Command(): string
    {
        return "DATA UPDATE timezone\tTZID=1\tTIME=00002359000023590000235900002359000023590000235900002359";
    }

    /**
     * Get all active registered devices excluding the source device.
     * Only returns devices where receiver_by_default is true (or NULL, for backward compat).
     * Devices with receiver_by_default = false are operate in send-only mode and will
     * not receive biometric template or user provisioning commands.
     */
    protected function getTargetDevices(?string $sourceSn)
    {
        $query = Devices::where(function ($q) {
                $q->where('is_active', 1)->orWhere('is_registration', 1);
            })
            ->whereNotNull('serial_number')
            ->where('serial_number', '!=', '')
            ->where('serial_number', '!=', 'Fail!');

        // Only apply receiver_by_default filter if the column exists (guards against older
        // installs and test environments where the column may not yet be present)
        if (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'receiver_by_default')) {
            $query->where(function ($q) {
                $q->whereNull('receiver_by_default')->orWhere('receiver_by_default', true);
            });
        }

        if (!empty($sourceSn)) {
            $query->where('serial_number', '!=', $sourceSn);
        }

        return $query->get()->unique('serial_number');
    }
}
