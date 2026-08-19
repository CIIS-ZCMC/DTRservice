<?php

namespace App\Repositories;

use App\Contracts\DeviceRepositoryInterface;
use App\Contracts\LogsRepositoryInterface;
use App\Models\Attendance;
use App\Models\AttendanceInformation;
use App\Models\Biometrics;
use App\Models\DeviceLogs;
use App\Models\EmployeeProfile;
use App\Models\ExternalEmployees;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LogsRepository implements LogsRepositoryInterface
{

 public function __construct(protected DeviceRepositoryInterface $deviceRepository) {}

    /**
     * Create a new attendance log
     */
    public function createLog(array $data): DeviceLogs
    {
        try {
            $device = $this->deviceRepository->findByIP($data['ip_address']);

            // Check device type flags
            if ($device && $device->is_registration == 1) {
                // Don't save to Device Logs for registration devices
                return new DeviceLogs();
            }

            if ($device && $device->for_attendance == 1) {
                // Redirect to attendance saving
                $this->saveForAttendance($data);
                return new DeviceLogs();
            }

            $employee = $this->getEmployeeNameAndStatus((int)$data['biometric_id']);
            $date_time = $data['dtr_date'] . ' ' . $data['dtr_time'];

            $logData = [
                'biometric_id' => $data['biometric_id'],
                'dtr_date' => $data['dtr_date'],
                'name' => $employee['name'] ?? 'Unknown',
                'date_time' => $date_time,
                'status' => $data['dtr_type'],
                'is_Shifting' => 0,
                'schedule' => null,
                'active' => 1,
                'device_name' => $device?->device_name ?? 'Unknown',
            ];

            return DB::transaction(function () use ($logData) {
                return DeviceLogs::create($logData);
            });
        } catch (\Exception $e) {
            Log::error('Error creating log: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function writeToFile(array $data): void
    {
        $today = now()->format('Y-m-d');
        $fileName = 'device_logs_' . $today . '.txt';

        // File headers
        $header = " -- Device Logs for: " . $today . PHP_EOL;
        $columns = "biometric_id | dtr_date | name | dtr_time | dtr_type | device_name" . PHP_EOL;
        $separator = str_repeat('-', 100) . PHP_EOL;

        try {
            $device = $this->deviceRepository->findByIP($data['ip_address']);

            // Check device type flags
            if ($device && $device->is_registration == 1) {
                // Don't write to file for registration devices
                return;
            }

            if ($device && $device->for_attendance == 1) {
                // Just return and don't do anything
                return;
            }

            $employee = $this->getEmployeeNameAndStatus($data['biometric_id']);

            $logData = [
                'biometric_id' => $data['biometric_id'],
                'dtr_date' => $data['dtr_date'],
                'name' => $employee['name'] ?? 'Unknown',
                'dtr_time' => $data['dtr_time'],
                'dtr_type' => $data['dtr_type'],
                'device_name' => $device?->device_name ?? 'Unknown',
            ];

            $format = implode(' | ', array_fill(0, count($logData), '%s'));
            $dataString = vsprintf($format, array_values($logData));

            // Check if file exists and get content
            $fileExists = Storage::disk('local')->exists($fileName);
            $existingContent = $fileExists ? Storage::disk('local')->get($fileName) : '';

            // Skip duplicate entries
            if (strpos($existingContent, $dataString) !== false) {
                return;
            }

            // Create file with headers if it doesn't exist
            if (! $fileExists) {
                Storage::disk('local')->put($fileName, $header . $separator . $columns . $separator);
            }

            // Append new log entry
            Storage::disk('local')->append($fileName, $dataString);
        } catch (\Exception $e) {
            Log::channel('device_logs')->error('SaveLogsLocal :: Error processing record: ' . $e->getMessage());
        }
    }

    public function writeStructuredLog(array $data, ?string $rawLine = null): void
    {
        try {
            $device = $this->deviceRepository->findByIP($data['ip_address']);
            $employee = $this->getEmployeeNameAndStatus($data['biometric_id']);

            $logData = [
                'biometric_id' => $data['biometric_id'],
                'dtr_date' => $data['dtr_date'],
                'name' => $employee['name'] ?? 'Unknown',
                'dtr_time' => $data['dtr_time'],
                'dtr_type' => $data['dtr_type'],
                'device_name' => $device?->device_name ?? 'Unknown',
                'ip_address' => $data['ip_address'] ?? null,
                'logged_at' => now()->toISOString(),
                'raw_line' => $rawLine,
            ];
            Log::channel('device_logs')->info('Device log entry', $logData);
        } catch (\Exception $e) {
            Log::channel('device_logs')->error('writeStructuredLog :: Error: ' . $e->getMessage());
        }
    }

    /**
     * Check if a log already exists by scanning log files instead of querying the DB.
     * Scans current device_logs.log + 2 most recent rotated files.
     */
    public function logExists(int $biometricId, string $dateTime): bool
    {
        $date = substr($dateTime, 0, 10);
        $time = substr($dateTime, 11, 8);
        $searchPattern = '"biometric_id":"' . $biometricId . '"';
        $datePattern = '"dtr_date":"' . $date . '"';
        $timePattern = '"dtr_time":"' . $time . '"';

        $files = glob(storage_path('logs/device_logs*.log'));
        if (!$files) {
            return false;
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $files = array_slice($files, 0, 3);

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) {
                continue;
            }

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = $lines[$i];
                if (strpos($line, 'Device log entry') === false) {
                    continue;
                }
                if (strpos($line, $searchPattern) !== false
                    && strpos($line, $datePattern) !== false
                    && strpos($line, $timePattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get logs by date range
     */
    public function getLogsByDateRange(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = DB::table('attendance_logs');

        if ($dateFrom) {
            $query->where('dtr_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('dtr_date', '<=', $dateTo);
        }

        return $query->get()->toArray();
    }

     public function getEmployeeNameAndStatus(int $biometricId): array
    {
        $isExternal = false;
        $name = null;

        $profile = EmployeeProfile::where('biometric_id', $biometricId)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->latest('id')
            ->first();

        if (!$profile) {
            $profile = EmployeeProfile::where('biometric_id', $biometricId)
                ->whereNull('deleted_at')
                ->latest('id')
                ->first();
        }

        if ($profile) {
            $name = $profile->personalInformation?->employeeName() ?? $profile->name();
        } else {
            $externalEmployee = ExternalEmployees::where('biometric_id', $biometricId)->first();
            if ($externalEmployee) {
                $name = $externalEmployee->getFullNameAttribute();
                $isExternal = true;
            }
        }

        return [
            'name' => $name,
            'is_external' => $isExternal
        ];
    }

    public function saveForAttendance(array $data): bool
    {
        try {
            // 1. Get active attendances
            $activeAttendances = Attendance::where("attendance_key", 1)
                ->get();

            $matchedAttendance = null;

            if ($activeAttendances->isEmpty()) {
                Log::channel('attendance_logs')->error('No active attendance found');
                Log::channel('failed_attendancelogs')->warning('Failed attendance log', [
                    'reason' => 'no_active_attendance',
                    'biometric_id' => $data['biometric_id'],
                    'dtr_date' => $data['dtr_date'],
                    'dtr_time' => $data['dtr_time'],
                    'dtr_type' => $data['dtr_type'] ?? null,
                    'full_data' => $data,
                ]);
                return true;
            }

            // Loop through active attendances and find matching open_date
            foreach ($activeAttendances as $attendance) {
                if ($attendance->open_date === $data['dtr_date']) {
                    $matchedAttendance = $attendance;
                    break;
                }
            }

            if (!$matchedAttendance) {
                Log::channel('attendance_logs')->info('No attendance matching device date', [
                    'device_date' => $data['dtr_date'],
                    'biometric_id' => $data['biometric_id'],
                    'full_data' => $data
                ]);
                Log::channel('failed_attendancelogs')->warning('Failed attendance log', [
                    'reason' => 'no_matched_date',
                    'biometric_id' => $data['biometric_id'],
                    'dtr_date' => $data['dtr_date'],
                    'dtr_time' => $data['dtr_time'],
                    'dtr_type' => $data['dtr_type'] ?? null,
                    'full_data' => $data,
                ]);
                return true;
            }

            // 2. Get employee profile
            $employee = EmployeeProfile::where('biometric_id', $data['biometric_id'])
                ->whereNull('deleted_at')
                ->whereNull('deactivated_at')
                ->latest('id')
                ->first();

            if (!$employee) {
                $employee = EmployeeProfile::where('biometric_id', $data['biometric_id'])
                    ->whereNull('deleted_at')
                    ->latest('id')
                    ->first();
            }

            if (!$employee) {
                Log::channel('attendance_logs')->error('Employee not found', ['biometric_id' => $data['biometric_id']]);
                Log::channel('failed_attendancelogs')->warning('Failed attendance log', [
                    'reason' => 'employee_not_found',
                    'biometric_id' => $data['biometric_id'],
                    'dtr_date' => $data['dtr_date'],
                    'dtr_time' => $data['dtr_time'],
                    'dtr_type' => $data['dtr_type'] ?? null,
                    'full_data' => $data,
                ]);
                return true;
            }

            // Get employee name using existing method
            $employeeNameData = $this->getEmployeeNameAndStatus((int)$data['biometric_id']);
            $employeeName = $employeeNameData['name'] ?? 'Unknown';

            $email = null;
            $assignedArea = $employee->assignArea ?? null;
            if ($employee->personalInformation && $employee->personalInformation->contact) {
                $email = $employee->personalInformation->contact->email_address ?? null;
            }

            // 3. Get area details
            if (!$assignedArea) {
                $areaDetails = null;
                $sector = null;
            } else {
                $areaInfo = $assignedArea->findDetails();
                $areaDetails = $areaInfo['details'] ?? null;
                $sector = $areaInfo['sector'] ?? null;
            }

            // 4. Save to AttendanceInformation
            $entryDateTime = $data['dtr_date'] . ' ' . $data['dtr_time'];
            $exists = AttendanceInformation::where('biometric_id', $data['biometric_id'])
                ->where('first_entry', $entryDateTime)
                ->exists();

            if ($exists) {
                Log::channel('attendance_logs')->info('Duplicate attendance log skipped', [
                    'biometric_id' => $data['biometric_id'],
                    'first_entry' => $entryDateTime,
                ]);
                return true;
            }

            AttendanceInformation::create([
                'biometric_id' => $data['biometric_id'],
                'name' => $employeeName,
                'area' => $areaDetails ? ($areaDetails->name ?? null) : null,
                'areacode' => $areaDetails ? ($areaDetails->code ?? null) : null,
                'sector' => $sector,
                'first_entry' => $data['dtr_date'] . ' ' . $data['dtr_time'],
                'last_entry' => null,
                'attendances_id' => $matchedAttendance ? $matchedAttendance->id : null,
                'email' => $email
            ]);

            Log::channel('attendance_logs')->info('Attendance saved successfully', [
                'biometric_id' => $data['biometric_id'],
                'attendances_id' => $matchedAttendance ? $matchedAttendance->id : null,
                'device_date' => $data['dtr_date']
            ]);
            return true;
        } catch (\Exception $e) {
            Log::channel('attendance_logs')->error('saveForAttendance :: Error: ' . $e->getMessage(), [
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);
            Log::channel('failed_attendancelogs')->warning('Failed attendance log', [
                'reason' => 'save_error',
                'biometric_id' => $data['biometric_id'],
                'dtr_date' => $data['dtr_date'],
                'dtr_time' => $data['dtr_time'],
                'dtr_type' => $data['dtr_type'] ?? null,
                'full_data' => $data,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }
}
