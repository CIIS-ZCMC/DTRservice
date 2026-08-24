<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\BiometricSyncService;
use Illuminate\Console\Command;

class SyncBiometricsToDevice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:sync-device 
                            {device_sn? : Target device serial number}
                            {--pin= : Specific biometric ID / PIN to sync}
                            {--all-devices : Push to all active registered devices}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision/sync user profile(s) and all enrolled biometric templates to a specific device or all devices';

    public function __construct(protected BiometricSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $deviceSn = $this->argument('device_sn');
        $pin = $this->option('pin');
        $allDevices = $this->option('all-devices');

        if (!$deviceSn && !$allDevices) {
            $this->error('Please specify a target device serial number or use --all-devices.');
            return 1;
        }

        // Get target devices
        $devices = [];
        if ($allDevices) {
            $devices = Devices::where('is_active', 1)
                ->whereNotNull('serial_number')
                ->where('serial_number', '!=', '')
                ->get();
        } else {
            $device = Devices::where('serial_number', $deviceSn)->first();
            if (!$device) {
                $this->error("Device with serial number {$deviceSn} not found.");
                return 1;
            }
            $devices = collect([$device]);
        }

        if ($devices->isEmpty()) {
            $this->warn('No active target devices found.');
            return 0;
        }

        // Get users to sync
        $usersQuery = Biometrics::whereNotNull('biometric')
            ->where('biometric', '!=', 'NOT_YET_REGISTERED')
            ->where('biometric', '!=', '');

        if ($pin) {
            $usersQuery->where('biometric_id', $pin);
        }

        $users = $usersQuery->get();
        $this->info("Found {$users->count()} user(s) to sync to " . $devices->count() . " device(s).");

        if ($users->isEmpty()) {
            return 0;
        }

        $bar = $this->output->createProgressBar($users->count() * $devices->count());
        $bar->start();

        $totalCommands = 0;

        foreach ($devices as $device) {
            foreach ($users as $user) {
                $count = $this->syncService->syncUserAndTemplatesToDevice($device->serial_number, (int)$user->biometric_id);
                $totalCommands += $count;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Successfully queued {$totalCommands} command(s) (USER + FINGERTMP) for " . $devices->count() . " device(s).");
        $this->line("Target devices will download and install the user profiles and templates automatically upon their next poll.");

        return 0;
    }
}
