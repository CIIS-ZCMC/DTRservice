<?php

namespace App\Services;

use App\Models\DeviceLogs;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class BiometricService
{
    /**
     * Sync time logs from biometric device
     * 
     * @param string $deviceId - Biometric device ID
     * @param string|null $dateFrom - Start date filter (Y-m-d)
     * @param string|null $dateTo - End date filter (Y-m-d)
     * @return array - Summary of synced logs
     */
    public function syncTimeLogs(string $deviceId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return DB::transaction(function () use ($deviceId, $dateFrom, $dateTo) {
            // Connect to biometric device API
            $deviceLogs = $this->fetchFromDevice($deviceId, $dateFrom, $dateTo);

            $syncedCount = 0;
            $skippedCount = 0;

            foreach ($deviceLogs as $logData) {
                // Business logic: Check if user exists
                $user = User::where('biometric_id', $logData['user_id'])->first();
                
                if (!$user) {
                    $skippedCount++;
                    continue;
                }

                // Business logic: Check if log already exists (prevent duplicates)
                $existingLog = DeviceLogs::where('device_id', $deviceId)
                    ->where('log_time', $logData['log_time'])
                    ->where('user_id', $user->id)
                    ->first();

                if ($existingLog) {
                    $skippedCount++;
                    continue;
                }

                // Create time log
                DeviceLogs::create([
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    'log_time' => $logData['log_time'],
                    'log_type' => $logData['log_type'] ?? 'check_in',
                    'synced_at' => now(),
                ]);

                $syncedCount++;
            }

            return [
                'success' => true,
                'synced' => $syncedCount,
                'skipped' => $skippedCount,
                'message' => "Synced {$syncedCount} time logs from device {$deviceId}"
            ];
        });
    }

    /**
     * Fetch logs from biometric device
     * Replace this with actual device API integration
     */
    protected function fetchFromDevice(string $deviceId, ?string $dateFrom, ?string $dateTo): array
    {
        // Example: HTTP request to biometric device API
        // $response = Http::timeout(30)->get("https://{$deviceId}/api/logs", [
        //     'date_from' => $dateFrom,
        //     'date_to' => $dateTo,
        // ]);
        // return $response->json();

        // Mock data for demonstration
        return [
            [
                'user_id' => 'EMP001',
                'log_time' => Carbon::now()->subHours(2),
                'log_type' => 'check_in',
            ],
            [
                'user_id' => 'EMP002',
                'log_time' => Carbon::now()->subHours(1),
                'log_type' => 'check_in',
            ],
        ];
    }

    /**
     * Get time logs with filters
     */
    public function getTimeLogs(array $filters = [])
    {
        $query = DeviceLogs::with('user');

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['device_id'])) {
            $query->where('device_id', $filters['device_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('log_time', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('log_time', '<=', $filters['date_to']);
        }

        if (isset($filters['log_type'])) {
            $query->where('log_type', $filters['log_type']);
        }

        return $query->orderBy('log_time', 'desc')
            ->paginate($filters['per_page'] ?? 50);
    }
}
