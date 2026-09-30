<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\BiometricSyncService;
use App\Services\DeviceCommandService;
use App\Services\DeviceService;
use Illuminate\Console\Command;

class DeleteBiometricUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:delete-user 
                            {pin : Employee Biometric ID / PIN to remove (comma-separated for multiple, e.g. 489,496)}
                            {device_sn? : Specific device serial number or IP (optional)}
                            {--all-devices : Dispatch user deletion command to all active devices}
                            {--with-db : Also delete the user record from the database biometrics table}
                            {--queue-only : Only queue command without direct TAD connection}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a user profile and all their biometric templates directly from target device(s) via TAD/SOAP, and optionally database';

    public function handle(
        BiometricSyncService $syncService, 
        DeviceCommandService $commandService,
        DeviceService $deviceService
    ): int {
        $rawPin = trim((string)$this->argument('pin'));
        $deviceSn = $this->argument('device_sn');
        $allDevices = $this->option('all-devices');
        $withDb = $this->option('with-db');
        $queueOnly = (bool)$this->option('queue-only');

        if (!$deviceSn && !$allDevices) {
            $this->error('Please specify a target device serial number or use --all-devices.');
            return 1;
        }

        $pins = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawPin)))));
        if (empty($pins)) {
            $this->error('No valid PIN provided.');
            return 1;
        }

        if ($allDevices) {
            $query = Devices::where('is_active', 1);
            $devices = $query->get()->unique(fn($d) => $d->serial_number ?: $d->ip_address);
        } else {
            $device = Devices::where('serial_number', $deviceSn)
                ->orWhere('ip_address', $deviceSn)
                ->first();
            if (!$device) {
                $this->error("Device with serial number / IP {$deviceSn} not found.");
                return 1;
            }
            $devices = collect([$device]);
        }

        $this->info("Initializing user biometric deletion for PIN(s): " . implode(', ', $pins) . " across {$devices->count()} device(s)...");

        $totalTadDeleted = 0;
        $totalQueued = 0;

        foreach ($devices as $dev) {
            $devName = $dev->device_name ?? $dev->ip_address;
            $this->line("<fg=yellow>Connecting to {$dev->ip_address} ({$devName})...</>");

            // Direct TAD SOAP deletion (adapted from DeleteBulkFromBiometricDevice)
            if (!$queueOnly && !empty($dev->ip_address)) {
                $result = $deviceService->deleteUsersFromDevice($dev, $pins, true);
                if ($result['tad_success']) {
                    foreach ($pins as $p) {
                        $userName = Biometrics::where('biometric_id', $p)->value('name') ?: "PIN {$p}";
                        $this->line("  <fg=green>[DIRECT TAD] {$dev->ip_address} Deleted -> {$p} ({$userName}) ✔</>");
                        $totalTadDeleted++;
                    }
                } else {
                    $this->warn("  <fg=red>[DIRECT TAD] Connection failed / offline --- {$dev->ip_address}</>");
                }
                $totalQueued += $result['push_queued_count'];
            } else {
                // Queue only
                if (!empty($dev->serial_number) && $dev->serial_number !== 'Fail!') {
                    foreach ($pins as $p) {
                        $commandService->queueCommand($dev->serial_number, "DATA DELETE USER PIN={$p}");
                        $totalQueued++;
                    }
                }
            }

            if (!empty($dev->serial_number) && $dev->serial_number !== 'Fail!') {
                foreach ($pins as $p) {
                    $this->line("  <fg=cyan>[ADMS QUEUE] Queued DATA DELETE USER PIN={$p} to {$dev->serial_number} ✔</>");
                }
            }

            $this->line("End of process for --- {$dev->ip_address} ({$devName})");
        }

        if ($allDevices) {
            $this->info("Completed deletion across {$devices->count()} active device(s). (Direct TAD: {$totalTadDeleted}, Push Queued: {$totalQueued})");
        } else {
            $this->info("Completed deletion for {$devices->first()->device_name}. (Direct TAD: {$totalTadDeleted}, Push Queued: {$totalQueued})");
        }

        if ($withDb) {
            foreach ($pins as $p) {
                $user = Biometrics::where('biometric_id', $p)
                    ->orWhere('hrbliz_biometric_id', $p)
                    ->first();
                if ($user) {
                    $user->delete();
                    $this->info("Deleted PIN {$p} from the database biometrics table.");
                } else {
                    $this->line("<fg=yellow>PIN {$p} does not exist in the database (orphan).</>");
                }
            }
        }

        return 0;
    }
}