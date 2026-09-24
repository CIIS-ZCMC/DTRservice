<?php

namespace App\Models;

use App\Services\RegistrationLogger;
use Illuminate\Database\Eloquent\Model;

class Biometrics extends Model
{
    public const FINGER_NAMES = [
        0 => 'Right Thumb',
        1 => 'Right Index',
        2 => 'Right Middle',
        3 => 'Right Ring',
        4 => 'Right Little',
        5 => 'Left Thumb',
        6 => 'Left Index',
        7 => 'Left Middle',
        8 => 'Left Ring',
        9 => 'Left Little',
    ];

   protected $table = "biometrics";

   protected $fillable = [
       'biometric_id',
       'hrbliz_biometric_id',
       'name',
       'privilege',
       'biometric',
       'face',
       'biophoto',
       'name_with_biometric',
   ];

   protected $casts = [
       'biometric_id' => 'integer',
       'hrbliz_biometric_id' => 'integer',
       'privilege' => 'integer',
   ];

    protected static function booted(): void
    {
        static::created(function (self $model) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('devices')) {
                return;
            }

            try {
                app(\App\Services\BiometricSyncService::class)->syncUserAndTemplatesToAll(null, (int)$model->biometric_id);
            } catch (\Throwable $th) {
                \Illuminate\Support\Facades\Log::channel('device_logs')->error('Biometrics::created sync error: ' . $th->getMessage());
            }
        });

        static::updated(function (self $model) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('devices')) {
                return;
            }

            try {
                $syncService = app(\App\Services\BiometricSyncService::class);

                // 1. Sync name or privilege changes
                if ($model->wasChanged(['name', 'privilege'])) {
                    $syncService->syncUserToAll(null, [
                        'PIN' => (int)$model->biometric_id,
                        'Name' => $model->name,
                        'Pri' => $model->privilege,
                    ]);
                }

                // 2. Sync template changes (additions, updates, deletions)
                if ($model->wasChanged('biometric')) {
                    $original = $model->getOriginal('biometric');
                    $current = $model->biometric;

                    $oldTemplates = [];
                    if (!empty($original) && $original !== 'NOT_YET_REGISTERED') {
                        $decodedOld = json_decode($original, true);
                        if (is_array($decodedOld)) {
                            foreach ($decodedOld as $item) {
                                $fid = $item['Finger_ID'] ?? $item['FID'] ?? null;
                                if ($fid !== null) {
                                    $oldTemplates[(string)$fid] = $item;
                                }
                            }
                        }
                    }

                    $newTemplates = [];
                    if (!empty($current) && $current !== 'NOT_YET_REGISTERED') {
                        $decodedNew = json_decode($current, true);
                        if (is_array($decodedNew)) {
                            foreach ($decodedNew as $item) {
                                $fid = $item['Finger_ID'] ?? $item['FID'] ?? null;
                                if ($fid !== null) {
                                    $newTemplates[(string)$fid] = $item;
                                }
                            }
                        }
                    }

                    // A. Delete removed fingerprints
                    $deletedFids = array_diff(array_keys($oldTemplates), array_keys($newTemplates));
                    foreach ($deletedFids as $fid) {
                        $syncService->deleteFingerprintFromAll(null, (int)$model->biometric_id, $fid);
                    }

                    // B. Queue new or modified fingerprints
                    foreach ($newTemplates as $fid => $item) {
                        $oldItem = $oldTemplates[$fid] ?? null;
                        $oldTemplate = $oldItem['Template'] ?? $oldItem['TMP'] ?? null;
                        $newTemplate = $item['Template'] ?? $item['TMP'] ?? null;

                        if (!$oldItem || $oldTemplate !== $newTemplate) {
                            $syncService->syncBiometricToAll(null, 'FINGERTMP', [
                                'PIN' => (int)$model->biometric_id,
                                'FID' => $fid,
                                'Size' => $item['Size'] ?? strlen($newTemplate ?? ''),
                                'Valid' => $item['Valid'] ?? '1',
                                'Template' => $newTemplate ?? '',
                            ]);
                        }
                    }
                }

                // 3. Sync face template changes
                if ($model->wasChanged('face') && !empty($model->face)) {
                    $faceData = is_array($model->face) ? $model->face : json_decode($model->face, true);
                    if (is_array($faceData)) {
                        $faceData['PIN'] = (int)$model->biometric_id;
                        $syncService->syncBiometricToAll(null, 'BIODATA', $faceData);
                    }
                }

                // 4. Sync biophoto changes
                if ($model->wasChanged('biophoto') && !empty($model->biophoto)) {
                    $photoData = is_array($model->biophoto) ? $model->biophoto : json_decode($model->biophoto, true);
                    if (is_array($photoData)) {
                        $photoData['PIN'] = (int)$model->biometric_id;
                        $syncService->syncBiometricToAll(null, 'BIOPHOTO', $photoData);
                    }
                }
            } catch (\Throwable $th) {
                \Illuminate\Support\Facades\Log::channel('device_logs')->error('Biometrics::updated sync error: ' . $th->getMessage());
            }
        });

        static::deleted(function (self $model) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('devices')) {
                return;
            }

            try {
                app(\App\Services\BiometricSyncService::class)->deleteUserFromAll(null, (int)$model->biometric_id, $model);
            } catch (\Throwable $th) {
                \Illuminate\Support\Facades\Log::channel('device_logs')->error('Biometrics::deleted sync error: ' . $th->getMessage());
            }
        });
    }

    /**
     * Find a biometric record by device PIN based on the device fleet mode (HRBLIZ vs Standard).
     *
     * @param int|string $pin
     * @param bool $isHrbliz Whether the originating device is marked as an HRBLIZ terminal
     * @return static|null
     */
    public static function findByDevicePin(int|string $pin, bool $isHrbliz = false): ?self
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            return null;
        }

        $intPin = (int)$pin;
        if ($isHrbliz) {
            return self::where('hrbliz_biometric_id', $intPin)->first();
        }

        return self::where('biometric_id', $intPin)->first();
    }

    /**
     * Get the device-appropriate PIN for this biometric record.
     * Returns hrbliz_biometric_id for HRBLIZ devices, or biometric_id for standard devices.
     *
     * @param \App\Models\Devices|bool $deviceOrIsHrbliz
     * @return int|null
     */
    public function getPinForDevice(\App\Models\Devices|bool $deviceOrIsHrbliz): ?int
    {
        $isHrbliz = $deviceOrIsHrbliz instanceof \App\Models\Devices 
            ? (bool)$deviceOrIsHrbliz->is_hrbliz 
            : (bool)$deviceOrIsHrbliz;

        if ($isHrbliz) {
            return $this->hrbliz_biometric_id ? (int)$this->hrbliz_biometric_id : null;
        }

        return $this->biometric_id ? (int)$this->biometric_id : null;
    }

   /**
    * Add or update a fingerprint template for a biometric user.
    * If the biometric field is null, empty, or 'NOT_YET_REGISTERED', it replaces it with the new template array.
    * If existing templates are present, it updates if Finger_ID matches, or appends if new.
    *
    * @param int $biometricId User's biometric ID / PIN
    * @param int|string $fingerId Finger ID (0-9)
    * @param int|string $size Template size
    * @param int|string $valid Validity flag (usually 1)
    * @param string $template Base64 / ZK template string
    * @param string|null $ipAddress
    * @param string|null $deviceSn
    * @param int $syncedDevicesCount
    * @param bool $isHrbliz
    * @return static
    */
   public static function saveFingerprintTemplate(
       int $biometricId,
       int|string $fingerId,
       int|string $size,
       int|string $valid,
       string $template,
       ?string $ipAddress = null,
       ?string $deviceSn = null,
       int $syncedDevicesCount = 0,
       bool $isHrbliz = false
   ): ?self {
       if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
           return null;
       }

       $device = $deviceSn && \Illuminate\Support\Facades\Schema::hasTable('devices') ? \App\Models\Devices::where('serial_number', $deviceSn)->first() : null;
       $effectiveHrbliz = $isHrbliz || ($device && (bool)$device->is_hrbliz);
       $record = self::findByDevicePin($biometricId, $effectiveHrbliz);

       if (!$record) {
           $name = null;
           if (\Illuminate\Support\Facades\Schema::hasTable('employee_profiles')) {
               $employeeProfile = EmployeeProfile::where('biometric_id', $biometricId)
                   ->whereNull('deleted_at')
                   ->latest('id')
                   ->first();

               if ($employeeProfile) {
                   $name = $employeeProfile->personalInformation?->employeeName() ?? $employeeProfile->name();
               }
           }

           if (!$name && \Illuminate\Support\Facades\Schema::hasTable('external_employees')) {
               $externalEmployee = ExternalEmployees::where('biometric_id', $biometricId)->first();
               if ($externalEmployee) {
                   $name = $externalEmployee->getFullNameAttribute();
               }
           }

           $record = new self();
           if ($effectiveHrbliz) {
               $record->hrbliz_biometric_id = $biometricId;
               $record->biometric_id = $biometricId;
           } else {
               $record->biometric_id = $biometricId;
           }
           $record->name = $name ?? 'Unknown';
           $record->privilege = 0;
       }

       // Capture previous state for registration verification audit log
       $existing = $record->biometric;
       $previousFids = [];
       if (!empty($existing) && $existing !== 'NOT_YET_REGISTERED') {
           $decoded = json_decode($existing, true);
           if (is_array($decoded)) {
               $previousFids = array_column($decoded, 'Finger_ID');
           }
       }

       $action = 'NEW_REGISTRATION';
       if ($existing === 'NOT_YET_REGISTERED') {
           $action = 'REPLACED_NOT_YET_REGISTERED';
       } elseif (!empty($previousFids)) {
           $action = in_array((string)$fingerId, array_map('strval', $previousFids)) 
               ? 'UPDATED_EXISTING_FINGER' 
               : 'APPENDED_NEW_FINGER';
       }

       $device = $deviceSn && \Illuminate\Support\Facades\Schema::hasTable('devices') ? \App\Models\Devices::where('serial_number', $deviceSn)->first() : null;
       $algo = $device ? $device->getFingerprintAlgorithm() : self::getTemplateAlgorithm($template);

       $record->addOrUpdateFingerprint($fingerId, $size, $valid, $template, $algo);
       $record->saveQuietly();

       $currentFids = [];
       $newDecoded = json_decode($record->biometric, true);
       if (is_array($newDecoded)) {
           $currentFids = array_column($newDecoded, 'Finger_ID');
       }

       RegistrationLogger::logFingerprintRegistration(
           $biometricId,
           $record->name,
           $fingerId,
           $size,
           $valid,
           $action,
           $previousFids,
           $currentFids,
           $ipAddress,
           $deviceSn,
           $syncedDevicesCount
       );

       return $record;
   }

    /**
     * Check if a specific fingerprint template is already stored and identical.
     *
     * @param int|string $biometricId
     * @param int|string $fingerId
     * @param string $template
     * @param bool $isHrbliz
     * @return bool True if exact template already exists in database
     */
    public static function isFingerprintIdentical(int|string $biometricId, int|string $fingerId, string $template, bool $isHrbliz = false): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            return false;
        }

        $record = self::findByDevicePin($biometricId, $isHrbliz);
        if (!$record || empty($record->biometric) || $record->biometric === 'NOT_YET_REGISTERED') {
            return false;
        }

        $templates = json_decode($record->biometric, true);
        if (!is_array($templates)) {
            return false;
        }

        $fingerIdStr = (string)$fingerId;
        $templateStr = (string)$template;

        foreach ($templates as $t) {
            if (isset($t['Finger_ID']) && (string)$t['Finger_ID'] === $fingerIdStr) {
                $existingTemplate = $t['Template'] ?? $t['TMP'] ?? '';
                if ($existingTemplate === $templateStr) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if user profile data from device is already identical to database.
     *
     * @param int|string $pin
     * @param array $userData
     * @param bool $isHrbliz
     * @return bool True if user exists and name/privilege match
     */
    public static function isUserIdentical(int|string $pin, array $userData, bool $isHrbliz = false): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            return false;
        }

        $record = self::findByDevicePin($pin, $isHrbliz);
        if (!$record) {
            return false;
        }

        $incomingName = $userData['Name'] ?? $userData['name'] ?? null;
        if ($incomingName !== null && trim($incomingName) !== '' && $incomingName !== $record->name) {
            return false;
        }

        $incomingPri = $userData['Pri'] ?? $userData['pri'] ?? $userData['Privilege'] ?? null;
        if ($incomingPri !== null) {
            $expectedPri = ((int)$incomingPri === 1 || (int)$incomingPri === 14) ? 1 : 0;
            if ((int)$record->privilege !== $expectedPri) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine the algorithm version ('v10' or 'v9') of a raw Base64 template string.
     */
    public static function getTemplateAlgorithm(string $template): string
    {
        $sub = substr($template, 0, 32);

        // Explicit v9 prefix or markers
        if (str_starts_with($template, 'V9_') || str_contains($template, 'ZKFP9')) {
            return 'v9';
        }

        // ZKFinger 10.0 base64 payloads commonly contain 'UzIx' or start with known v10 prefixes
        if (str_contains($sub, 'UzIx') || str_starts_with($sub, 'SIl') || str_starts_with($sub, 'SoV') || str_starts_with($sub, 'Skt') || str_starts_with($sub, 'Ss1') || str_starts_with($sub, 'S7F')) {
            return 'v10';
        }

        $decoded = @base64_decode($sub);
        if ($decoded !== false && (str_contains($decoded, 'SS21') || str_contains($decoded, 'ZKSS') || str_contains($decoded, 'ZKFP10'))) {
            return 'v10';
        }

        // Default to v10 because ZCMC hospital fleet is predominantly ZKFinger 10.0
        return 'v10';
    }

    /**
     * Get enrolled templates for this user filtered by algorithm ('v10' or 'v9').
     * If target is v10 and user has legacy untagged templates, returns them.
     * If target is v9 and user only has v10 templates, returns empty array to prevent -1004 error.
     */
    public function getTemplatesForAlgorithm(string $algo = 'v10'): array
    {
        if (empty($this->biometric) || $this->biometric === 'NOT_YET_REGISTERED') {
            return [];
        }

        $decoded = is_array($this->biometric) ? $this->biometric : json_decode($this->biometric, true);
        if (!is_array($decoded)) {
            return [];
        }

        $matched = [];
        $hasTaggedVersion = false;

        foreach ($decoded as $t) {
            $tVersion = $t['Version'] ?? null;
            if ($tVersion !== null) {
                $hasTaggedVersion = true;
                if ($tVersion === $algo) {
                    $matched[] = $t;
                }
            } else {
                $rawTmpl = $t['Template'] ?? $t['TMP'] ?? '';
                $detected = self::getTemplateAlgorithm($rawTmpl);
                if ($detected === $algo) {
                    $matched[] = $t;
                }
            }
        }

        if (!empty($matched)) {
            return $matched;
        }

        // If target is v10 and user has legacy untagged templates, allow them
        if ($algo === 'v10' && !$hasTaggedVersion) {
            return $decoded;
        }

        return [];
    }

    /**
     * Add or update fingerprint template on the current model instance.
     * Supports multi-algorithm storage: a user can hold both a v10 and v9 template for the same finger.
     */
    public function addOrUpdateFingerprint(
        int|string $fingerId,
        int|string $size,
        int|string $valid,
        string $template,
        ?string $version = null
    ): self {
        $existing = $this->biometric;
        $templates = [];

        if (!empty($existing) && $existing !== 'NOT_YET_REGISTERED') {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) {
                $templates = $decoded;
            }
        }

        $fingerIdStr = (string)$fingerId;
        $sizeStr = (string)$size;
        $validStr = (string)$valid;
        $templateStr = (string)$template;
        $algoVersion = $version ?? self::getTemplateAlgorithm($templateStr);

        $newEntry = [
            'Finger_ID' => $fingerIdStr,
            'Size' => $sizeStr,
            'Valid' => $validStr,
            'Template' => $templateStr,
            'Version' => $algoVersion,
        ];

        $found = false;
        foreach ($templates as $idx => $t) {
            $tFid = (string)($t['Finger_ID'] ?? $t['FID'] ?? '');
            $tRaw = $t['Template'] ?? $t['TMP'] ?? '';
            $tVer = $t['Version'] ?? self::getTemplateAlgorithm($tRaw);

            // Match on both Finger_ID and Algorithm Version
            if ($tFid === $fingerIdStr && $tVer === $algoVersion) {
                $templates[$idx] = $newEntry;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $templates[] = $newEntry;
        }

        $this->biometric = json_encode($templates);
        return $this;
    }

    /**
     * Delete a specific fingerprint template from a user and optionally sync deletion to all devices.
     *
     * @param int $biometricId User's biometric ID / PIN
     * @param int|string $fingerId Finger ID (0-9)
     * @param bool $syncDevices Whether to queue DATA DELETE FINGERTMP for all active devices
     * @return bool True if deleted, false if not found
     */
    public static function removeFingerprintTemplate(int $biometricId, int|string $fingerId, bool $syncDevices = true): bool
    {
        $record = self::where('biometric_id', $biometricId)->first();
        if (!$record || empty($record->biometric) || $record->biometric === 'NOT_YET_REGISTERED') {
            return false;
        }

        $templates = json_decode($record->biometric, true);
        if (!is_array($templates)) {
            return false;
        }

        $filtered = array_values(array_filter($templates, function ($t) use ($fingerId) {
            return isset($t['Finger_ID']) && (string)$t['Finger_ID'] !== (string)$fingerId;
        }));

        if (count($filtered) === count($templates)) {
            return false; // FID was not found
        }

        $record->biometric = empty($filtered) ? 'NOT_YET_REGISTERED' : json_encode($filtered);
        $record->save();

        if ($syncDevices) {
            app(\App\Services\BiometricSyncService::class)->deleteFingerprintFromAll(null, $biometricId, $fingerId);
        }

        \Illuminate\Support\Facades\Log::channel('registration_logs')->info("Fingerprint FID {$fingerId} deleted from database for PIN {$biometricId}", [
            'biometric_id' => $biometricId,
            'deleted_fid' => $fingerId,
            'remaining_fids' => array_column($filtered, 'Finger_ID'),
        ]);

        return true;
    }

   public function employeeProfile()
    {
        return $this->hasOne(EmployeeProfile::class, 'biometric_id', 'biometric_id')->latest('id');
    }

    public function employeeProfiles()
    {
        return $this->hasMany(EmployeeProfile::class, 'biometric_id', 'biometric_id');
    }

    public function externalProfile(){
          return $this->hasOne(ExternalEmployee::class, 'biometric_id', 'biometric_id');
    }

    public function getSchedules($date){
        $externalEmployee = $this->externalProfile;
      
        if (!$externalEmployee) {
            return null;
        }
        return ExternalSchedule::where('external_employee_id', $externalEmployee->id)
            ->where('dtr_date', $date)
            ->first();
    }

    /**
     * Parse and extract structured fingerprint templates from raw/json biometric column.
     * Supports both modern (Finger_ID, Template, Version, Size) and legacy (FID, TMP) formats.
     *
     * @param mixed $biometricData
     * @return array<int, array{finger_id: string, finger_name: string, template: string, size: int, valid: string, version: string, hash: string}>
     */
    public static function extractFingerprintTemplates(mixed $biometricData): array
    {
        if (empty($biometricData) || $biometricData === 'NOT_YET_REGISTERED') {
            return [];
        }

        $decoded = is_array($biometricData) ? $biometricData : json_decode((string)$biometricData, true);
        if (!is_array($decoded)) {
            return [];
        }

        $templates = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $fid = (string)($item['Finger_ID'] ?? $item['FID'] ?? $item['finger_id'] ?? $item['fid'] ?? '');
            $rawTemplate = (string)($item['Template'] ?? $item['TMP'] ?? $item['template'] ?? $item['tmp'] ?? '');
            $rawTemplate = trim($rawTemplate);

            if ($rawTemplate === '') {
                continue;
            }

            $fingerInt = is_numeric($fid) ? (int)$fid : null;
            $fingerName = $fingerInt !== null ? (self::FINGER_NAMES[$fingerInt] ?? "Slot {$fid}") : "Slot {$fid}";
            $size = (int)($item['Size'] ?? $item['size'] ?? strlen($rawTemplate));
            $valid = (string)($item['Valid'] ?? $item['valid'] ?? '1');
            $version = (string)($item['Version'] ?? $item['version'] ?? self::getTemplateAlgorithm($rawTemplate));

            $templates[] = [
                'finger_id' => $fid,
                'finger_name' => $fingerName,
                'template' => $rawTemplate,
                'size' => $size,
                'valid' => $valid,
                'version' => $version,
                'hash' => md5($rawTemplate),
            ];
        }

        return $templates;
    }

    /**
     * Find duplicate/identical biometric fingerprint templates for a specific employee PIN.
     * Searches all other enrolled users in the biometrics table to detect identical templates.
     *
     * @param int|string $pin
     * @return array
     */
    public static function findDuplicateTemplates(int|string $pin): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            return [
                'success' => false,
                'error' => 'biometrics table does not exist',
                'target_pin' => (int)$pin,
            ];
        }

        $target = self::where('biometric_id', (int)$pin)->first();
        if (!$target) {
            return [
                'success' => false,
                'error' => "Biometric record for PIN {$pin} was not found in the database.",
                'target_pin' => (int)$pin,
            ];
        }

        $targetTemplates = self::extractFingerprintTemplates($target->biometric);

        $enrolledFingers = array_map(function ($t) {
            return [
                'finger_id' => $t['finger_id'],
                'finger_name' => $t['finger_name'],
                'size' => $t['size'],
                'version' => $t['version'],
                'preview' => substr($t['template'], 0, 16) . '...' . substr($t['template'], -10),
            ];
        }, $targetTemplates);

        if (empty($targetTemplates)) {
            return [
                'success' => true,
                'target_pin' => (int)$pin,
                'target_name' => $target->name,
                'enrolled_templates_count' => 0,
                'enrolled_fingers' => [],
                'has_duplicates' => false,
                'duplicates_count' => 0,
                'internal_duplicates' => [],
                'duplicates' => [],
                'message' => "PIN {$pin} ({$target->name}) does not have any enrolled fingerprint templates.",
            ];
        }

        // Check internal duplicate fingers within target PIN itself
        $internalDuplicates = [];
        $targetHashesSeen = [];
        foreach ($targetTemplates as $t) {
            $h = $t['hash'];
            if (isset($targetHashesSeen[$h])) {
                $prev = $targetHashesSeen[$h];
                $internalDuplicates[] = [
                    'finger_id_1' => $prev['finger_id'],
                    'finger_name_1' => $prev['finger_name'],
                    'finger_id_2' => $t['finger_id'],
                    'finger_name_2' => $t['finger_name'],
                    'algorithm' => $t['version'],
                    'size' => $t['size'],
                    'match_type' => 'INTERNAL_SAME_PIN_DUPLICATE',
                ];
            } else {
                $targetHashesSeen[$h] = $t;
            }
        }

        // Build target hash lookup map: hash => array of target templates
        $targetHashMap = [];
        foreach ($targetTemplates as $t) {
            $targetHashMap[$t['hash']][] = $t;
        }

        // Search through all other records in the biometrics table
        $duplicates = [];
        $otherRecords = self::where('biometric_id', '!=', (int)$pin)
            ->whereNotNull('biometric')
            ->where('biometric', '!=', '')
            ->where('biometric', '!=', 'NOT_YET_REGISTERED')
            ->cursor(['id', 'biometric_id', 'name', 'biometric']);

        foreach ($otherRecords as $other) {
            $otherTemplates = self::extractFingerprintTemplates($other->biometric);
            foreach ($otherTemplates as $otherTmpl) {
                $otherHash = $otherTmpl['hash'];
                if (isset($targetHashMap[$otherHash])) {
                    foreach ($targetHashMap[$otherHash] as $matchingTarget) {
                        $duplicates[] = [
                            'target_pin' => (int)$pin,
                            'target_name' => $target->name,
                            'target_finger_id' => $matchingTarget['finger_id'],
                            'target_finger_name' => $matchingTarget['finger_name'],
                            'matched_pin' => (int)$other->biometric_id,
                            'matched_name' => $other->name ?? 'Unknown',
                            'matched_finger_id' => $otherTmpl['finger_id'],
                            'matched_finger_name' => $otherTmpl['finger_name'],
                            'is_same_finger_slot' => ((string)$matchingTarget['finger_id'] === (string)$otherTmpl['finger_id']),
                            'algorithm' => $matchingTarget['version'] ?: $otherTmpl['version'],
                            'size' => $matchingTarget['size'],
                            'match_type' => 'EXACT_TEMPLATE_IDENTICAL',
                            'template_preview' => substr($matchingTarget['template'], 0, 16) . '...' . substr($matchingTarget['template'], -10),
                        ];
                    }
                }
            }
        }

        return [
            'success' => true,
            'target_pin' => (int)$pin,
            'target_name' => $target->name,
            'enrolled_templates_count' => count($targetTemplates),
            'enrolled_fingers' => $enrolledFingers,
            'has_duplicates' => (count($duplicates) > 0 || count($internalDuplicates) > 0),
            'duplicates_count' => count($duplicates),
            'internal_duplicates' => $internalDuplicates,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Scan entire biometrics table to find all duplicate fingerprint templates across all PINs.
     *
     * @return array
     */
    public static function findAllDuplicateTemplates(): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            return [];
        }

        $allRecords = self::whereNotNull('biometric')
            ->where('biometric', '!=', '')
            ->where('biometric', '!=', 'NOT_YET_REGISTERED')
            ->cursor(['id', 'biometric_id', 'name', 'biometric']);

        $templateMap = [];

        foreach ($allRecords as $record) {
            $templates = self::extractFingerprintTemplates($record->biometric);
            foreach ($templates as $t) {
                $templateMap[$t['hash']][] = [
                    'pin' => (int)$record->biometric_id,
                    'name' => $record->name ?? 'Unknown',
                    'finger_id' => $t['finger_id'],
                    'finger_name' => $t['finger_name'],
                    'size' => $t['size'],
                    'version' => $t['version'],
                    'template_preview' => substr($t['template'], 0, 16) . '...' . substr($t['template'], -10),
                ];
            }
        }

        $duplicateGroups = [];
        foreach ($templateMap as $hash => $entries) {
            $uniquePins = array_unique(array_column($entries, 'pin'));
            if (count($uniquePins) > 1 || count($entries) > 1) {
                $duplicateGroups[] = [
                    'template_hash' => $hash,
                    'algorithm' => $entries[0]['version'] ?? 'v10',
                    'size' => $entries[0]['size'] ?? 0,
                    'template_preview' => $entries[0]['template_preview'] ?? '',
                    'unique_pins_count' => count($uniquePins),
                    'pins_involved' => array_values($uniquePins),
                    'entries_count' => count($entries),
                    'entries' => $entries,
                ];
            }
        }

        return $duplicateGroups;
    }

    /**
     * Search the biometrics table to find identical matches for a set of given templates.
     * Useful for checking templates extracted from a physical terminal or DB against other enrolled users.
     *
     * @param array $templates List of templates, each containing at least 'template' and optional metadata ('finger_id', 'source', etc.)
     * @param int|string|null $excludePin Optional PIN to exclude from matches (usually the target employee PIN)
     * @return array Matches found with details of the matched PIN, slot, and employee name
     */
    public static function findMatchesForTemplates(array $templates, int|string|null $excludePin = null): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics') || empty($templates)) {
            return [];
        }

        // Build a normalized template map for incoming templates: hash => array of templates
        $targetHashMap = [];
        foreach ($templates as $t) {
            $rawTmpl = trim((string)($t['template'] ?? $t['Template'] ?? $t['TMP'] ?? ''));
            if ($rawTmpl === '') {
                continue;
            }
            $hash = md5($rawTmpl);
            $targetHashMap[$hash][] = [
                'finger_id' => (string)($t['finger_id'] ?? $t['Finger_ID'] ?? $t['FID'] ?? '0'),
                'finger_name' => $t['finger_name'] ?? (self::FINGER_NAMES[(int)($t['finger_id'] ?? 0)] ?? "Slot " . ($t['finger_id'] ?? 0)),
                'size' => (int)($t['size'] ?? $t['Size'] ?? strlen($rawTmpl)),
                'version' => (string)($t['version'] ?? $t['Version'] ?? self::getTemplateAlgorithm($rawTmpl)),
                'template' => $rawTmpl,
                'source' => $t['source'] ?? 'device',
                'device_sn' => $t['device_sn'] ?? null,
                'device_name' => $t['device_name'] ?? null,
                'is_ghost' => (bool)($t['is_ghost'] ?? false),
            ];
        }

        if (empty($targetHashMap)) {
            return [];
        }

        $query = self::whereNotNull('biometric')
            ->where('biometric', '!=', '')
            ->where('biometric', '!=', 'NOT_YET_REGISTERED');

        if ($excludePin !== null) {
            $query->where('biometric_id', '!=', (int)$excludePin);
        }

        $records = $query->cursor(['id', 'biometric_id', 'name', 'biometric']);
        $matches = [];

        foreach ($records as $other) {
            $otherTemplates = self::extractFingerprintTemplates($other->biometric);
            foreach ($otherTemplates as $otherTmpl) {
                $otherHash = $otherTmpl['hash'];
                if (isset($targetHashMap[$otherHash])) {
                    foreach ($targetHashMap[$otherHash] as $targetTmpl) {
                        // Confirm exact template string match
                        if ($targetTmpl['template'] === $otherTmpl['template']) {
                            $matches[] = [
                                'target_finger_id' => $targetTmpl['finger_id'],
                                'target_finger_name' => $targetTmpl['finger_name'],
                                'target_source' => $targetTmpl['source'],
                                'target_device_sn' => $targetTmpl['device_sn'],
                                'target_device_name' => $targetTmpl['device_name'],
                                'is_ghost_on_device' => $targetTmpl['is_ghost'],
                                'matched_pin' => (int)$other->biometric_id,
                                'matched_name' => $other->name ?? 'Unknown',
                                'matched_finger_id' => (string)$otherTmpl['finger_id'],
                                'matched_finger_name' => $otherTmpl['finger_name'],
                                'is_same_finger_slot' => ((string)$targetTmpl['finger_id'] === (string)$otherTmpl['finger_id']),
                                'algorithm' => $otherTmpl['version'] ?: $targetTmpl['version'],
                                'size' => $targetTmpl['size'],
                                'template_hash' => $otherHash,
                                'match_type' => 'EXACT_TEMPLATE_IDENTICAL',
                                'template_preview' => substr($targetTmpl['template'], 0, 16) . '...' . substr($targetTmpl['template'], -10),
                            ];
                        }
                    }
                }
            }
        }

        return $matches;
    }
}
