<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\BiometricSyncService;
use App\Services\DeviceCommandService;
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
                            {--all-devices : Push to all active registered devices}
                            {--no-clean : Do not delete unenrolled finger slots from devices}
                            {--table : Display full summary table of pushed biometrics in console}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision/sync user profile(s) and all enrolled biometric templates to a specific device or all devices (cleans unenrolled finger slots by default)';

    public function __construct(
        protected BiometricSyncService $syncService,
        protected DeviceCommandService $commandService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');

        $deviceSn = $this->argument('device_sn');
        $pin = $this->option('pin');
        $allDevices = $this->option('all-devices');
        $cleanUnused = !$this->option('no-clean');
        $showTable = $this->option('table') || !empty($pin);

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
        $hasFaceCol = \Illuminate\Support\Facades\Schema::hasColumn('biometrics', 'face');
        $hasPhotoCol = \Illuminate\Support\Facades\Schema::hasColumn('biometrics', 'biophoto');

        $usersQuery = Biometrics::where(function ($q) use ($hasFaceCol, $hasPhotoCol) {
            $q->where(function ($sub) {
                $sub->whereNotNull('biometric')
                    ->where('biometric', '!=', 'NOT_YET_REGISTERED')
                    ->where('biometric', '!=', '');
            });

            if ($hasFaceCol) {
                $q->orWhere(function ($sub) {
                    $sub->whereNotNull('face')
                        ->where('face', '!=', '');
                });
            }

            if ($hasPhotoCol) {
                $q->orWhere(function ($sub) {
                    $sub->whereNotNull('biophoto')
                        ->where('biophoto', '!=', '');
                });
            }
        });

        if ($pin) {
            $usersQuery->where('biometric_id', $pin);
        }

        $totalUsers = (clone $usersQuery)->count();
        $totalDevices = $devices->count();
        $this->info("Found {$totalUsers} user(s) to sync to {$totalDevices} device(s).");

        if ($totalUsers === 0) {
            return 0;
        }

        $bar = $this->output->createProgressBar($totalUsers * $totalDevices);
        $bar->start();

        $totalCommands = 0;
        $tableRows = [];
        $pushTime = now()->format('Y-m-d H:i:s');

        $usersQuery->chunk(100, function ($usersChunk) use ($devices, &$totalCommands, &$tableRows, $bar, $cleanUnused, $showTable, $pushTime) {
            $batch = [];
            foreach ($usersChunk as $user) {
                $commandStrings = $this->syncService->generateUserProvisionCommands($user, $cleanUnused);
                $cmdCount = count($commandStrings);

                foreach ($devices as $device) {
                    foreach ($commandStrings as $cmd) {
                        $batch[] = [
                            'device_sn' => $device->serial_number,
                            'command' => $cmd,
                        ];
                    }

                    // Log each push event
                    \App\Services\RegistrationLogger::logPushSync(
                        $user->biometric_id,
                        $user->name,
                        $device,
                        $cmdCount
                    );

                    if ($showTable && count($tableRows) < 500) {
                        $tableRows[] = [
                            'biometric_id' => $user->biometric_id,
                            'name' => $user->name ?? 'Unknown',
                            'device_name' => $device->device_name ?? 'Unknown',
                            'serial_number' => $device->serial_number,
                            'commands' => $cmdCount,
                            'time_pushed' => $pushTime,
                        ];
                    }

                    $bar->advance();
                }
            }

            if (!empty($batch)) {
                $totalCommands += $this->commandService->queueCommandsBatch($batch);
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($showTable && !empty($tableRows)) {
            $this->table(
                ['Biometric ID', 'Name', 'Device Name', 'Serial Number', 'Commands', 'Time Pushed'],
                $tableRows
            );
        }

        $today = now()->format('Y-m-d');
        $this->info("Successfully queued {$totalCommands} command(s) for {$totalDevices} device(s).");
        $this->line("Push audit log written to: storage/logs/sync_pushed_{$today}.txt");
        $this->line("Target devices will download and install the user profiles and templates automatically upon their next poll.");

        return 0;
    }
}
