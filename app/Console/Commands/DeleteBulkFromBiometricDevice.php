<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\DeviceCommandService;
use App\Services\DeviceService;
use Illuminate\Console\Command;

class DeleteBulkFromBiometricDevice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:biometric-delete 
                            {pins?* : Employee Biometric ID(s) / PIN(s) to remove (e.g. 489 496 632)}
                            {--pin= : Specific PIN or comma-separated PINs (e.g. 489,496,632)}
                            {--ip=* : Specific device IP address(es) to target}
                            {--device_sn= : Specific device serial number to target}
                            {--all-devices : Target all active devices (default)}
                            {--with-db : Also delete employee biometric records from the database}
                            {--test : Dry run mode without executing deletion}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete multiple users directly from biometric devices via TAD/SOAP';

    /**
     * Execute the console command.
     */
    public function handle(DeviceService $deviceService, DeviceCommandService $commandService): int
    {
        $this->info("Initializing process ...");

        $test = (bool)$this->option('test');

        // Resolve PINs from arguments or --pin option
        $pins = $this->argument('pins') ?: [];
        if ($pinOpt = $this->option('pin')) {
            $optPins = array_map('trim', explode(',', (string)$pinOpt));
            $pins = array_merge($pins, $optPins);
        }

        $pins = array_values(array_unique(array_filter(array_map('trim', $pins))));

        if (empty($pins)) {
            if ($this->input->isInteractive()) {
                $answer = $this->ask('Enter Employee Biometric ID(s) / PIN(s) to delete (comma or space separated)');
                if ($answer) {
                    $parts = preg_split('/[\s,]+/', trim($answer));
                    $pins = array_values(array_unique(array_filter($parts)));
                }
            }
        }

        if (empty($pins)) {
            $this->error('No biometric PIN(s) provided. Please specify one or more PINs to delete.');
            $this->line('Example: php artisan app:biometric-delete 489 496 632');
            $this->line('Example: php artisan app:biometric-delete --pin=489,496 --with-db');
            return 1;
        }

        // Standard IP addresses matching UMIS defaults
        $defaultIps = [
            "192.168.5.163", // LIVE - New 163
            "192.168.5.159", // LIVE Ward 1
            "192.168.5.158",
            "192.168.5.165",
            "192.168.5.187",
            "192.168.5.183",
            "192.168.5.184",
            "192.168.5.185",
            "192.168.5.160",
            "192.168.5.161",
            "192.168.5.162",
            "192.168.5.164",
            "192.168.5.186",
        ];

        // Resolve target devices
        $ipFilter = (array)$this->option('ip');
        $deviceSnFilter = $this->option('device_sn');

        $query = Devices::where('is_active', 1);

        if (!empty($ipFilter)) {
            $query->whereIn('ip_address', $ipFilter);
        } elseif ($deviceSnFilter) {
            $query->where('serial_number', $deviceSnFilter);
        }

        $devices = $query->get();

        // If no devices in DB matched the query but default IPs exist, fallback to querying default IPs
        if ($devices->isEmpty() && empty($ipFilter) && !$deviceSnFilter) {
            $devices = Devices::whereIn('ip_address', $defaultIps)->get();
        }

        if ($devices->isEmpty()) {
            $this->error("No active devices found to process.");
            return 1;
        }

        $this->info("Targeting " . count($pins) . " PIN(s) across " . $devices->count() . " device(s)...");

        foreach ($devices as $device) {
            $this->warn("Connecting to  $device->ip_address");

            if ($tad = $deviceService->checkDeviceConnection($device)) {
                $this->info("Connection successful -------- $device->ip_address  ");

                if ($test) {
                    $this->line("  [TEST MODE] Dry run enabled, skipping deletion for $device->ip_address");
                    continue;
                }

                foreach ($pins as $pin) {
                    $emp = Biometrics::where('biometric_id', $pin)
                        ->orWhere('hrbliz_biometric_id', $pin)
                        ->first();
                    $empName = $emp?->name ?? "User {$pin}";

                    // Candidate PINs (badge PIN vs HRBLIZ PIN)
                    $candidatePins = [(int)$pin];
                    if ($device->is_hrbliz && !empty($emp?->hrbliz_biometric_id)) {
                        $candidatePins[] = (int)$emp->hrbliz_biometric_id;
                    }
                    if (!empty($emp?->biometric_id)) {
                        $candidatePins[] = (int)$emp->biometric_id;
                    }
                    $candidatePins = array_values(array_unique(array_filter($candidatePins)));

                    foreach ($candidatePins as $cPin) {
                        try {
                            $tad->delete_template(['pin' => $cPin]);
                            $tad->delete_user(['pin' => $cPin]);
                        } catch (\Throwable $th) {
                            $this->error("Error deleting PIN {$cPin} on {$device->ip_address}: " . $th->getMessage());
                        }
                    }

                    $this->line("$device->ip_address Deleted -> {$pin} {$empName}  ✔");

                    // Secondary ADMS push queue backup
                    if (!empty($device->serial_number) && $device->serial_number !== 'Fail!') {
                        $commandService->queueCommand($device->serial_number, "DATA DELETE USER PIN={$pin}");
                    }
                }
            } else {
                $this->error("Connection failed --- $device->ip_address");
            }

            $this->info("End of process for --- $device->ip_address ");
        }

        // Database deletion if --with-db requested
        if ($this->option('with-db') && !$test) {
            foreach ($pins as $pin) {
                $user = Biometrics::where('biometric_id', $pin)
                    ->orWhere('hrbliz_biometric_id', $pin)
                    ->first();
                if ($user) {
                    $user->delete();
                    $this->info("Deleted PIN {$pin} from the database biometrics table.");
                } else {
                    $this->line("<fg=yellow>PIN {$pin} does not exist in the database (orphan).</>");
                }
            }
        }

        $this->info("Process completed successfully.");
        return 0;
    }
}
