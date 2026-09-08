<?php

namespace App\Models;

use App\Services\RegistrationLogger;
use Illuminate\Database\Eloquent\Model;

class Biometrics extends Model
{
   protected $table = "biometrics";

   protected $fillable = [
       'biometric_id',
       'name',
       'privilege',
       'biometric',
       'face',
       'biophoto',
       'name_with_biometric',
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
                app(\App\Services\BiometricSyncService::class)->deleteUserFromAll(null, (int)$model->biometric_id);
            } catch (\Throwable $th) {
                \Illuminate\Support\Facades\Log::channel('device_logs')->error('Biometrics::deleted sync error: ' . $th->getMessage());
            }
        });
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
       int $syncedDevicesCount = 0
   ): ?self {
       if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
           return null;
       }

       $record = self::where('biometric_id', $biometricId)->first();

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
           $record->biometric_id = $biometricId;
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

       $record->addOrUpdateFingerprint($fingerId, $size, $valid, $template);
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
     * @return bool True if exact template already exists in database
     */
    public static function isFingerprintIdentical(int|string $biometricId, int|string $fingerId, string $template): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            return false;
        }

        $record = self::where('biometric_id', (int)$biometricId)->first();
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
     * @return bool True if user exists and name/privilege match
     */
    public static function isUserIdentical(int|string $pin, array $userData): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
            return false;
        }

        $record = self::where('biometric_id', (int)$pin)->first();
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
    * Add or update fingerprint template on the current model instance.
    */
   public function addOrUpdateFingerprint(
       int|string $fingerId,
       int|string $size,
       int|string $valid,
       string $template
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

       $newEntry = [
           'Finger_ID' => $fingerIdStr,
           'Size' => $sizeStr,
           'Valid' => $validStr,
           'Template' => $templateStr,
       ];

       $found = false;
       foreach ($templates as $idx => $t) {
           if (isset($t['Finger_ID']) && (string)$t['Finger_ID'] === $fingerIdStr) {
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
}
