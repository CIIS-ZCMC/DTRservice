<?php

namespace App\Repositories;

use App\Contracts\DeviceRepositoryInterface;
use App\Contracts\LogsRepositoryInterface;
use App\Models\Biometrics;
use App\Models\DeviceLogs;
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

    //  'biometric_id',
    //     'name',
    //     'dtr_date',
    //     'date_time',
    //     'status',
    //     'is_Shifting',
    //     'schedule',
    //     'active',
    //     'device_name'
       return DB::transaction(function () use ($data) {


           return DeviceLogs::create($data);
       });
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

    public function writeStructuredLog(array $data): void
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
            ];
            Log::channel('device_logs')->info('Device log entry', $logData);
        } catch (\Exception $e) {
            Log::channel('device_logs')->error('writeStructuredLog :: Error: ' . $e->getMessage());
        }
    }

    /**
     * Check if a log already exists
     */
    public function logExists(int $biometricId, string $dateTime): bool
    {
        return DB::table('attendance_logs')
            ->where('biometric_id', $biometricId)
            ->where('date_time', $dateTime)
            ->exists();
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
            $user = Biometrics::join('employee_profiles', 'biometrics.biometric_id', '=', 'employee_profiles.biometric_id')
                ->where('biometrics.biometric_id', $biometricId)
                ->select('biometrics.*')
                ->first();
            if (!$user) {
                $externalEmployee = ExternalEmployees::where('biometric_id', $biometricId)->first();
                if ($externalEmployee) {
                    $name = $externalEmployee->getFullNameAttribute();
                    $isExternal = true;
                }
            } else {
                $name = $user->employeeProfile->name();
            }
            return [
                'name' => $name,
                'is_external' => $isExternal
            ];
    }
}
