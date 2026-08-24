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
       'name_with_biometric',
   ];

    protected static function booted(): void
    {
        static::updated(function (self $model) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('devices')) {
                return;
            }

            // Automatically detect if any FIDs were deleted from the biometric JSON column
            if ($model->wasChanged('biometric')) {
                $original = $model->getOriginal('biometric');
                $current = $model->biometric;

                $oldFids = [];
                if (!empty($original) && $original !== 'NOT_YET_REGISTERED') {
                    $decodedOld = json_decode($original, true);
                    if (is_array($decodedOld)) {
                        $oldFids = array_column($decodedOld, 'Finger_ID');
                    }
                }

                $newFids = [];
                if (!empty($current) && $current !== 'NOT_YET_REGISTERED') {
                    $decodedNew = json_decode($current, true);
                    if (is_array($decodedNew)) {
                        $newFids = array_column($decodedNew, 'Finger_ID');
                    }
                }

                $deletedFids = array_diff($oldFids, $newFids);
                foreach ($deletedFids as $fid) {
                    app(\App\Services\BiometricSyncService::class)->deleteFingerprintFromAll(null, (int)$model->biometric_id, $fid);
                }
            }
        });

        static::deleted(function (self $model) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('devices')) {
                return;
            }
            app(\App\Services\BiometricSyncService::class)->deleteUserFromAll(null, (int)$model->biometric_id);
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
       $record->save();

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
