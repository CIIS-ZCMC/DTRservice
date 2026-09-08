<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\BiometricSyncService;
use App\Services\DeviceCommandService;
use Illuminate\Console\Command;

class DeleteBiometricUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:delete-user 
                            {pin : Employee Biometric ID / PIN to remove}
                            {device_sn? : Specific device serial number (optional)}
                            {--all-devices : Dispatch user deletion command to all active devices}
                            {--with-db : Also delete the user record from the database biometrics table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a user profile and all their biometric templates from target device(s) or all devices, and optionally database';

    public function handle(BiometricSyncService $syncService, DeviceCommandService $commandService): int
    {
        $pin = (string)$this->argument('pin');
        $deviceSn = $this->argument('device_sn');
        $allDevices = $this->option('all-devices');
        $withDb = $this->option('with-db');

        if (!$deviceSn && !$allDevices) {
            $this->error('Please specify a target device serial number or use --all-devices.');
            return 1;
        }

        $command = "DATA DELETE USER PIN={$pin}";
        $queuedCount = 0;

        if ($allDevices) {
            $devices = Devices::where('is_active', 1)
                ->whereNotNull('serial_number')
                ->where('serial_number', '!=', '')
                ->where('serial_number', '!=', 'Fail!')
                ->get()
                ->unique('serial_number');

            foreach ($devices as $dev) {
                $commandService->queueCommand($dev->serial_number, $command);
                $queuedCount++;
            }
            $this->info("Queued DATA DELETE USER PIN={$pin} to {$queuedCount} active device(s).");
        } else {
            $device = Devices::where('serial_number', $deviceSn)->first();
            if (!$device) {
                $this->error("Device with serial number {$deviceSn} not found.");
                return 1;
            }
            $commandService->queueCommand($deviceSn, $command);
            $this->info("Queued DATA DELETE USER PIN={$pin} to device {$device->device_name} ({$deviceSn}).");
        }

        if ($withDb) {
            $user = Biometrics::where('biometric_id', $pin)->first();
            if ($user) {
                $user->delete();
                $this->info("Deleted PIN {$pin} from the database biometrics table.");
            } else {
                $this->line("<fg=yellow>PIN {$pin} does not exist in the database (orphan).</>");
            }
        }

        return 0;
    }
}