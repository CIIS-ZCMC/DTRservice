<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\BiometricSyncService;
use App\Services\DeviceCommandService;
use Illuminate\Console\Command;
use TADPHP\TADFactory;

class CheckDeviceFingerprints extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:check-device 
                            {pin : Employee Biometric ID / PIN to inspect}
                            {device_sn? : Target device serial number}
                            {--all-devices : Query all active registered biometric devices}
                            {--clean : Automatically purge detected ghost fingerprint slots from physical devices}
                            {--force : Bypass confirmation prompt when cleaning}
                            {--timeout=2 : Connection timeout per device in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Query physical biometric terminal(s) in real-time via TAD/SOAP to inspect registered user profile and fingerprint templates (slots 0-9) for a specific employee PIN, with optional ghost slot purging';

    public const FINGER_NAMES = [
        0 => 'Right Thumb',
        1 => 'Right Index',
        2 => 'Right Middle',
        3 => 'Right Ring',
        4 => 'Right Little',
        5 => 'Left Thumb',
        6 => 'Left Index',
        7 => 'Left Middle',
        8 => 'Left Ring',
        9 => 'Left Little',
    ];

    public function __construct(
        protected DeviceCommandService $commandService,
        protected BiometricSyncService $syncService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pin = (int)$this->argument('pin');
        $deviceSn = $this->argument('device_sn');
        $allDevices = (bool)$this->option('all-devices');
        $clean = (bool)$this->option('clean');
        $force = (bool)$this->option('force');

        if (!$deviceSn && !$allDevices) {
            $this->error('Please specify a target device serial number or use --all-devices.');
            $this->line('Example (single device): php artisan biometrics:check-device 493 UCR6254000009');
            $this->line('Example (all devices):    php artisan biometrics:check-device 493 --all-devices');
            $this->line('Example (inspect & clean): php artisan biometrics:check-device 493 --all-devices --clean');
            return 1;
        }

        // 1. Fetch Central Database Record
        $bioUser = Biometrics::where('biometric_id', $pin)->first();
        $dbSlots = [];

        if ($bioUser && !empty($bioUser->biometric) && $bioUser->biometric !== 'NOT_YET_REGISTERED') {
            $decoded = is_array($bioUser->biometric) ? $bioUser->biometric : json_decode($bioUser->biometric, true);
            if (is_array($decoded)) {
                foreach ($decoded as $t) {
                    $fid = (int)($t['Finger_ID'] ?? $t['FID'] ?? 0);
                    $size = $t['Size'] ?? strlen($t['Template'] ?? $t['TMP'] ?? '');
                    $dbSlots[$fid] = $size;
                }
            }
        }

        $this->newLine();
        $this->info("========================================================================================");
        $this->info("   BIOMETRIC DEVICE LIVE FINGERPRINT INSPECTION — PIN {$pin}");
        $this->info("========================================================================================");

        $dbSlotLabels = [];
        foreach ($dbSlots as $slot => $size) {
            $label = self::FINGER_NAMES[$slot] ?? "Slot {$slot}";
            $dbSlotLabels[] = "Slot {$slot} ({$label}, Size: {$size})";
        }

        $this->line("<fg=cyan;options=bold>Central Database Masterlist Authority:</>");
        $this->line(" • Name:       " . ($bioUser?->name ?? '<fg=yellow>Not found in biometrics table</>'));
        $this->line(" • Privilege:  " . ((int)($bioUser?->privilege ?? 0) === 14 ? 'SuperAdmin (14)' : 'Standard User (0)'));
        $this->line(" • Enrolled:   " . (!empty($dbSlotLabels) ? implode(', ', $dbSlotLabels) : '<fg=yellow>None / NOT_YET_REGISTERED</>'));
        $this->line(" • NIR Face:   " . (!empty($bioUser?->face) ? 'Registered' : 'None'));
        $this->line(" • Photo:      " . (!empty($bioUser?->biophoto) ? 'Registered' : 'None'));
        $this->newLine();

        // 2. Resolve Target Devices
        if ($allDevices) {
            $devices = Devices::where('is_active', 1)
                ->whereNotNull('serial_number')
                ->where('serial_number', '!=', '')
                ->where('serial_number', '!=', 'Fail!')
                ->get()
                ->unique('serial_number');
        } else {
            $device = Devices::where('serial_number', $deviceSn)->first();
            if (!$device) {
                $this->error("Device with serial number {$deviceSn} not found in database.");
                return 1;
            }
            $devices = collect([$device]);
        }

        if ($devices->isEmpty()) {
            $this->warn('No active target devices found to inspect.');
            return 0;
        }

        $this->line("Querying " . $devices->count() . " device(s) in real-time via TAD / SOAP...\n");

        $tableRows = [];
        $hasMismatch = false;
        $hasGhostSlots = false;
        $ghostsToClean = [];

        foreach ($devices as $dev) {
            $row = $this->inspectDevice($dev, $pin, $dbSlots);

            if ($row['_has_ghost']) {
                $hasGhostSlots = true;
                $ghostsToClean[] = [
                    'device' => $dev,
                    'ghost_fids' => $row['_ghost_fids'],
                    'tad' => $row['_tad'],
                    'all_ghosts' => $row['_all_ghosts'],
                ];
            }

            if ($row['_status'] !== 'IN_SYNC') {
                $hasMismatch = true;
            }

            unset($row['_has_ghost'], $row['_status'], $row['_ghost_fids'], $row['_tad'], $row['_all_ghosts']);
            $tableRows[] = $row;
        }

        $this->table(
            ['Device Name', 'Serial Number', 'IP Address', 'Connection', 'User On Device', 'Hardware Enrolled Fingers', 'Status vs DB'],
            $tableRows
        );

        // 3. Ghost Cleanup Action
        if ($clean && !empty($ghostsToClean)) {
            $this->newLine();
            $totalGhostFids = array_sum(array_map(fn($g) => count($g['ghost_fids']), $ghostsToClean));
            $deviceCount = count($ghostsToClean);

            if (!$force) {
                if (!$this->confirm("Found {$totalGhostFids} ghost slot(s) across {$deviceCount} device(s). Purge them now?", true)) {
                    $this->line('Ghost slot purging cancelled by user.');
                    return 0;
                }
            }

            $this->info("Purging ghost slots for PIN {$pin} across {$deviceCount} device(s)...");
            $purgedCommands = 0;

            foreach ($ghostsToClean as $target) {
                $targetDev = $target['device'];
                $ghostFids = $target['ghost_fids'];
                $tad = $target['tad'];
                $allGhosts = $target['all_ghosts'];

                // 1. Instant SOAP wipe if all templates on terminal are ghosts
                if ($tad && $allGhosts) {
                    try {
                        $tad->delete_template(['pin' => $pin]);
                        $this->line(" • <fg=green>[INSTANT SOAP WIPE]</> Cleared all templates for PIN {$pin} on {$targetDev->device_name} ({$targetDev->serial_number})");
                    } catch (\Throwable $th) {
                        // Fallback to ADMS delete
                    }
                }

                // 2. Queue ADMS DATA DELETE FINGERTMP for each ghost slot
                foreach ($ghostFids as $gfid) {
                    $cmd = "DATA DELETE FINGERTMP\tPIN={$pin}\tFID={$gfid}";
                    $this->commandService->queueCommand($targetDev->serial_number, $cmd);
                    $purgedCommands++;
                }

                $this->line(" • Queued " . count($ghostFids) . " ADMS deletion command(s) (FID " . implode(',', $ghostFids) . ") for {$targetDev->device_name} ({$targetDev->serial_number})");
            }

            $this->newLine();
            $this->info("✅ Successfully queued {$purgedCommands} ghost slot deletion command(s) across {$deviceCount} device(s).");
            $this->line("   Terminals will purge these ghost slots immediately upon their next poll cycle.");
            return 0;
        }

        // 4. Diagnostics & Recommendations
        $this->newLine();
        if ($hasGhostSlots) {
            $this->warn("⚠️  GHOST FINGERPRINTS DETECTED: One or more physical terminals have extra finger slots enrolled that do not exist in the database!");
            $this->line("   To purge all detected ghost slots automatically, re-run with <fg=yellow>--clean</>:");
            $this->line("   <fg=yellow>php artisan biometrics:check-device " . ($deviceSn ? "{$pin} {$deviceSn}" : "{$pin} --all-devices") . " --clean</>\n");
        } elseif ($hasMismatch) {
            $this->warn("⚠️  SYNC DISCREPANCY: Some terminals are missing this user profile or enrolled fingerprints.");
            $this->line("   Provision/sync this employee across all active terminals:");
            $this->line("   <fg=yellow>php artisan biometrics:sync-device --all-devices --pin={$pin}</>\n");
        } else {
            $this->info("✅ All online terminals are 100% synchronized with the database masterlist for PIN {$pin}.");
        }

        return 0;
    }

    /**
     * Connect directly to a physical terminal and inspect user info and finger slots 0-9.
     */
    protected function inspectDevice(Devices $device, int $pin, array $dbSlots): array
    {
        $deviceName = $device->device_name ?? 'Unknown';
        $deviceSn = $device->serial_number;
        $ip = $device->ip_address ?? '0.0.0.0';

        $emptyRow = [
            'name' => $deviceName,
            'sn' => $deviceSn,
            'ip' => $ip,
            'connection' => '<fg=red>OFFLINE</>',
            'user' => '-',
            'fingers' => '-',
            'status' => '<fg=red>OFFLINE</>',
            '_has_ghost' => false,
            '_ghost_fids' => [],
            '_tad' => null,
            '_all_ghosts' => false,
            '_status' => 'OFFLINE',
        ];

        if (empty($ip) || $ip === '0.0.0.0' || ($ip === '127.0.0.1' && !app()->runningUnitTests())) {
            return $emptyRow;
        }

        try {
            $options = [
                'ip' => $ip,
                'com_key' => (int)($device->com_key ?? 0),
                'soap_port' => (int)($device->soap_port ?: 80),
                'udp_port' => (int)($device->udp_port ?: 4370),
                'encoding' => 'utf-8',
            ];

            $tad = (new TADFactory($options))->get_instance();

            if (!$tad->is_alive()) {
                return $emptyRow;
            }

            // 1. Fetch User Info
            $uRes = $tad->get_user_info(['pin' => $pin]);
            $uArr = $uRes->to_array();
            $userRow = $uArr['Row'] ?? null;

            if (empty($userRow)) {
                return [
                    'name' => $deviceName,
                    'sn' => $deviceSn,
                    'ip' => $ip,
                    'connection' => '<fg=green>ONLINE</>',
                    'user' => '<fg=red>NOT_FOUND</>',
                    'fingers' => '<fg=yellow>None</>',
                    'status' => '<fg=red>MISSING_USER</>',
                    '_has_ghost' => false,
                    '_ghost_fids' => [],
                    '_tad' => $tad,
                    '_all_ghosts' => false,
                    '_status' => 'MISSING_USER',
                ];
            }

            $privilege = (int)($userRow['Privilege'] ?? 0);
            $userRoleLabel = ($privilege === 14) ? 'SuperAdmin' : ($privilege === 1 ? 'Admin' : 'User');
            $userDisplay = "<fg=green>YES</> ({$userRoleLabel})";

            // 2. Scan Finger Slots 0 through 9
            $deviceSlots = [];
            for ($slot = 0; $slot <= 9; $slot++) {
                try {
                    $tRes = $tad->get_user_template(['pin' => $pin, 'finger_id' => $slot]);
                    $tArr = $tRes->to_array();
                    if (!empty($tArr['Row']) && !empty($tArr['Row']['Template'])) {
                        $size = $tArr['Row']['Size'] ?? strlen($tArr['Row']['Template']);
                        $deviceSlots[$slot] = $size;
                    }
                } catch (\Throwable) {
                    // Slot is empty
                }
            }

            // Format enrolled slots for display
            $fingerLabels = [];
            foreach ($deviceSlots as $slot => $size) {
                $label = self::FINGER_NAMES[$slot] ?? "Slot {$slot}";
                $fingerLabels[] = "Slot {$slot} ({$label})";
            }
            $fingersDisplay = !empty($fingerLabels) ? implode(', ', $fingerLabels) : '<fg=yellow>None</>';

            // 3. Compare with DB
            $dbFids = array_keys($dbSlots);
            $devFids = array_keys($deviceSlots);
            sort($dbFids);
            sort($devFids);

            $ghostFids = array_values(array_diff($devFids, $dbFids));
            $missingFids = array_values(array_diff($dbFids, $devFids));

            $hasGhost = !empty($ghostFids);
            $allGhosts = !empty($devFids) && empty($dbFids);
            $statusCol = '<fg=green>IN_SYNC</>';
            $statusCode = 'IN_SYNC';

            if (!empty($ghostFids) && !empty($missingFids)) {
                $statusCol = "<fg=yellow>MISMATCH (Ghost: " . implode(',', $ghostFids) . ", Missing: " . implode(',', $missingFids) . ")</>";
                $statusCode = 'MISMATCH';
            } elseif (!empty($ghostFids)) {
                $statusCol = "<fg=yellow;options=bold>EXTRA_GHOST (Slot " . implode(',', $ghostFids) . ")</>";
                $statusCode = 'EXTRA_GHOST';
            } elseif (!empty($missingFids)) {
                $statusCol = "<fg=red>MISSING (Slot " . implode(',', $missingFids) . ")</>";
                $statusCode = 'MISSING_SLOT';
            }

            return [
                'name' => $deviceName,
                'sn' => $deviceSn,
                'ip' => $ip,
                'connection' => '<fg=green>ONLINE</>',
                'user' => $userDisplay,
                'fingers' => $fingersDisplay,
                'status' => $statusCol,
                '_has_ghost' => $hasGhost,
                '_ghost_fids' => $ghostFids,
                '_tad' => $tad,
                '_all_ghosts' => $allGhosts,
                '_status' => $statusCode,
            ];
        } catch (\Throwable $e) {
            $emptyRow['status'] = '<fg=red>ERROR: ' . substr($e->getMessage(), 0, 30) . '</>';
            return $emptyRow;
        }
    }
}
