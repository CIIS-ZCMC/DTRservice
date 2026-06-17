<?php

namespace App\Console\Commands;

use App\Services\BiometricService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Devices;

class SyncBiometricLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometric:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync time logs from biometric devices';

    /**
     * Execute the console command.
     */
    public function handle(BiometricService $biometricService): int
    {
      
    

        $this->info("Starting sync for device:");

        return 1;

        try {
            $result = $biometricService->syncTimeLogs($deviceId, $dateFrom, $dateTo);

            $this->info("Sync completed successfully!");
            $this->info("Synced: {$result['synced']} logs");
            $this->info("Skipped: {$result['skipped']} logs");
            $this->info("Message: {$result['message']}");

            // Log to file for monitoring
            Log::info('Biometric sync completed', [
                'device_id' => $deviceId,
                'synced' => $result['synced'],
                'skipped' => $result['skipped'],
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            
            // Log error for monitoring
            Log::error('Biometric sync failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
