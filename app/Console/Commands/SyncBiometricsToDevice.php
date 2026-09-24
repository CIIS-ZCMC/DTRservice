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
                            {--clean-unused-fingers : Delete unenrolled finger slots from devices (default behavior)}
                            {--no-clean : Do not delete unenrolled finger slots from devices}
                            {--sync-timezone : Note: Timezone 1 (24/7 all-access: Grp=1, TZ=1) is automatically applied to all user profiles}
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
        @ini_set('memory_limit', '2048M');
        \Illuminate\Support\Facades\DB::disableQueryLog();

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
            $query = Devices::where('is_active', 1)
                ->whereNotNull('serial_number')
                ->where('serial_number', '!=', '')
                ->where('serial_number', '!=', 'Fail!');

            if (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'is_hrbliz') &&
                \Illuminate\Support\Facades\Schema::hasColumn('devices', 'receiver_by_default')) {
                $query->canReceiveSync();
            } elseif (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'receiver_by_default')) {
                $query->where(function ($q) {
                    $q->whereNull('receiver_by_default')->orWhere('receiver_by_default', 1);
                });
            }

            $devices = $query->get()->unique('serial_number');
        } else {
            $device = Devices::where('serial_number', $deviceSn)->first();
            if (!$device) {
                $this->error("Device with serial number {$deviceSn} not found.");
                return 1;
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'is_hrbliz') &&
                \Illuminate\Support\Facades\Schema::hasColumn('devices', 'receiver_by_default')) {
                if (!$device->canReceiveSync()) {
                    if ($device->is_hrbliz) {
                        $this->error("Cannot sync to device [{$deviceSn}]: Device is configured as HRBLIZ (is_hrbliz = 1) with receiver_by_default disabled (receiver_by_default = " . ($device->receiver_by_default ? '1' : '0') . "). Provisioning only runs on HRBLIZ terminals if receiver_by_default is set to 1.");
                    } else {
                        $this->error("Cannot sync to device [{$deviceSn}]: Device has receiver_by_default set to 0 (attend-only mode). Provisioning cannot run unless receiver_by_default is set to 1.");
                    }
                    return 1;
                }
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
            $hasHrblizIdCol = \Illuminate\Support\Facades\Schema::hasColumn('biometrics', 'hrbliz_biometric_id');
            $usersQuery->where(function ($q) use ($pin, $hasHrblizIdCol) {
                $q->where('biometric_id', $pin);
                if ($hasHrblizIdCol) {
                    $q->orWhere('hrbliz_biometric_id', $pin);
                }
            });
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

        // Timezone 1 (24/7 all-access: Grp=1, TZ=1) is already automatically assigned to every user profile
        // in generateUserProvisionCommands(). Standalone ADMS terminals reject raw "DATA UPDATE timezone"
        // table commands with -1004 (unsupported table).
        if ($this->option('sync-timezone')) {
            $this->info("Timezone 1 (24/7 access: Grp=1, TZ=1) is automatically enforced on all provisioned user profiles.");
        }

        $usersQuery->chunk(100, function ($usersChunk) use ($devices, &$totalCommands, &$tableRows, $bar, $cleanUnused, $showTable, $pushTime) {
            $batch = [];
            foreach ($usersChunk as $user) {
                foreach ($devices as $device) {
                    $commandStrings = $this->syncService->generateUserProvisionCommands($user, $cleanUnused, $device);
                    $cmdCount = count($commandStrings);

                    if ($cmdCount === 0) {
                        $bar->advance();
                        continue;
                    }

                    foreach ($commandStrings as $cmd) {
                        $batch[] = [
                            'device_sn' => $device->serial_number,
                            'command' => $cmd,
                        ];
                    }

                    // On throwing or selecting a biometric_id:
                    // is_hrbliz = 1 --> hrbliz_biometric_id
                    // is_hrbliz = 0 --> biometric_id
                    $targetPin = ($device->is_hrbliz && !empty($user->hrbliz_biometric_id))
                        ? (int)$user->hrbliz_biometric_id
                        : (int)$user->biometric_id;

                    // Log each push event
                    \App\Services\RegistrationLogger::logPushSync(
                        $targetPin,
                        $user->name,
                        $device,
                        $cmdCount
                    );

                    if ($showTable && count($tableRows) < 500) {
                        $tableRows[] = [
                            'biometric_id' => $targetPin,
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
                unset($batch);
            }
            gc_collect_cycles();
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
