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
                            {--fix : Automatically sync missing templates from DB and purge ghost slots to achieve 100% sync}
                            {--clean : Automatically purge detected ghost fingerprint slots from physical devices}
                            {--force : Bypass confirmation prompt when cleaning or fixing}
                            {--timeout=2 : Connection timeout per device in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Query physical biometric terminal(s) in real-time via TAD/SOAP to inspect registered user profile and fingerprint templates (slots 0-9) for a specific employee PIN, with optional auto-fix or ghost slot purging';

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
        $fix = (bool)$this->option('fix');
        $clean = (bool)$this->option('clean');
        $force = (bool)$this->option('force');

        if (!$deviceSn && !$allDevices) {
            $this->error('Please specify a target device serial number or use --all-devices.');
            $this->line('Example (single device): php artisan biometrics:check-device 493 UCR6254000009');
            $this->line('Example (all devices):    php artisan biometrics:check-device 493 --all-devices');
            $this->line('Example (auto-fix sync):  php artisan biometrics:check-device 493 --all-devices --fix');
            $this->line('Example (purge ghosts):   php artisan biometrics:check-device 493 --all-devices --clean');
            return 1;
        }

        // 1. Fetch Central Database Record (supports canonical biometric_id or hrbliz_biometric_id)
        $bioUser = Biometrics::where('biometric_id', $pin)
            ->orWhere('hrbliz_biometric_id', $pin)
            ->first();
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
            $devices = Devices::where(function ($q) {
                    $q->where('is_active', 1)->orWhere('is_registration', 1);
                })
                ->whereNotNull('serial_number')
                ->where('serial_number', '!=', '')
                ->where('serial_number', '!=', 'Fail!')
                ->whereNotNull('ip_address')
                ->where('ip_address', '!=', '')
                ->where('ip_address', '!=', '0.0.0.0')
                ->orderByDesc('is_registration')
                ->get()
                ->unique('ip_address');
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

        $timeout = max(1, (int)$this->option('timeout'));

        $this->line("Querying " . $devices->count() . " device(s) in real-time via TAD / SOAP...\n");

        $hasMissingSlots = false;
        $hasGhostSlots = false;
        $ghostsToClean = [];
        $devicesToFix = [];
        $detectedAlgos = [];
        $tableRows = [];

        $rows = $this->inspectDevices($devices, $bioUser, $pin, $dbSlots, $timeout);

        foreach ($rows as $item) {
            $dev = $item['device'];
            $deviceTargetPin = $item['target_pin'];
            $inspectPin = $item['inspect_pin'];
            $row = $item['row'];

            if ($row['_status'] !== 'OFFLINE' && !empty($row['_algo']) && $row['_algo'] !== 'Unknown') {
                $detectedAlgos[$row['_algo']] = ($detectedAlgos[$row['_algo']] ?? 0) + 1;
            }

            if (!empty($row['_missing_fids'])) {
                $hasMissingSlots = true;
            }

            if ($row['_has_ghost']) {
                $hasGhostSlots = true;
                $ghostsToClean[] = [
                    'device' => $dev,
                    'target_pin' => $deviceTargetPin,
                    'ghost_fids' => $row['_ghost_fids'],
                    'candidate_pins' => $row['_candidate_pins'] ?? [$inspectPin],
                    'tad' => $row['_tad'],
                    'all_ghosts' => $row['_all_ghosts'],
                ];
            }

            if ($row['_status'] !== 'IN_SYNC' && $row['_status'] !== 'OFFLINE') {
                $devicesToFix[] = [
                    'device' => $dev,
                    'target_pin' => $deviceTargetPin,
                    'candidate_pins' => $row['_candidate_pins'] ?? [$inspectPin],
                    'ghost_fids' => $row['_ghost_fids'] ?? [],
                    'missing_fids' => $row['_missing_fids'] ?? [],
                    'tad' => $row['_tad'],
                ];
            }

            unset($row['_has_ghost'], $row['_status'], $row['_ghost_fids'], $row['_missing_fids'], $row['_candidate_pins'], $row['_tad'], $row['_all_ghosts'], $row['_algo']);
            $tableRows[] = $row;
        }

        $this->table(
            ['Device Name', 'Serial Number', 'IP Address', 'Algo (ZKFP)', 'Connection', 'User On Device', 'Hardware Enrolled Fingers', 'Status vs DB'],
            $tableRows
        );

        // 3. Auto-Fix Action (--fix)
        if ($fix && !empty($devicesToFix)) {
            $this->newLine();
            $deviceCount = count($devicesToFix);

            if (!$force) {
                if (!$this->confirm("Found sync discrepancies on {$deviceCount} device(s). Automatically push missing templates and clean ghost slots now?", true)) {
                    $this->line('Auto-fix cancelled by user.');
                    return 0;
                }
            }

            $this->info("Pushing missing templates and synchronizing PIN {$pin} across {$deviceCount} device(s)...");
            $totalCommands = 0;

            foreach ($devicesToFix as $fixTarget) {
                $targetDev = $fixTarget['device'];
                if (!$targetDev->canReceiveSync()) {
                    $reason = $targetDev->is_hrbliz
                        ? 'HRBLIZ device has receiver_by_default = 0 (send-only attendance terminal)'
                        : 'device has receiver_by_default = 0 (send-only mode)';
                    $this->line(" • Skipping {$targetDev->device_name} ({$targetDev->serial_number}): {$reason}.");
                    continue;
                }
                $targetPin = $fixTarget['target_pin'] ?? $pin;
                if ($targetDev->is_hrbliz && $targetPin === null) {
                    $this->line(" • <fg=yellow>Skipping HRBLIZ device {$targetDev->device_name} ({$targetDev->serial_number}): employee has no hrbliz_biometric_id mapped.</>");
                    continue;
                }

                $candPins = $fixTarget['candidate_pins'] ?? [$targetPin];
                $ghostFids = $fixTarget['ghost_fids'] ?? [];
                $missingFids = $fixTarget['missing_fids'] ?? [];
                $tad = $fixTarget['tad'] ?? null;

                // 1. Sync User profile and DB templates (enforces Grp=1, TZ=1 on device-resolved PIN)
                $cmdCount = $this->syncService->syncUserAndTemplatesToDevice($targetDev->serial_number, $bioUser ?? $targetPin, false);

                // 2. If the terminal has an internal PIN distinct from target PIN, ensure Grp=1 TZ=1 is set on that internal PIN too
                if ($bioUser) {
                    $name = $bioUser->name ?? 'Unknown';
                    $privilege = $bioUser->privilege ?? 0;
                    $devicePri = ((int)$privilege === 1 || (int)$privilege === 14) ? 14 : 0;
                    foreach ($candPins as $cPin) {
                        if ($cPin !== $targetPin && $cPin > 0) {
                            $userCmd = "DATA USER PIN={$cPin}\tPIN2={$targetPin}\tName={$name}\tPri={$devicePri}\tPasswd=\tCard=0\tGrp=1\tTZ=1";
                            $this->commandService->queueCommand($targetDev->serial_number, $userCmd);
                            $cmdCount++;
                        }
                    }
                }

                // 3. Push missing templates to ALL candidate PINs (including internal terminal PIN)
                if ($bioUser && !empty($missingFids)) {
                    $devAlgo = $targetDev->getFingerprintAlgorithm();
                    $targetTemplates = $bioUser->getTemplatesForAlgorithm($devAlgo);
                    $templatesByFid = [];
                    foreach ($targetTemplates as $t) {
                        $fid = (int)($t['Finger_ID'] ?? $t['FID'] ?? 0);
                        $templatesByFid[$fid] = $t;
                    }

                    foreach ($missingFids as $mfid) {
                        $t = $templatesByFid[$mfid] ?? null;
                        if ($t) {
                            $size = $t['Size'] ?? strlen($t['Template'] ?? $t['TMP'] ?? '');
                            $valid = $t['Valid'] ?? '1';
                            $tmp = $t['Template'] ?? $t['TMP'] ?? '';

                            // Instant SOAP push if terminal is live
                            if ($tad) {
                                try {
                                    foreach ($candPins as $cPin) {
                                        $tad->set_user_template([
                                            'pin' => $cPin,
                                            'finger_id' => $mfid,
                                            'size' => $size,
                                            'valid' => $valid,
                                            'template' => $tmp,
                                        ]);
                                    }
                                    $this->line(" • <fg=green>[INSTANT SOAP PUSH]</> Pushed missing Slot {$mfid} to {$targetDev->device_name} ({$targetDev->serial_number})");
                                } catch (\Throwable) {
                                    // Fallback to ADMS queued command below
                                }
                            }

                            // Queue ADMS DATA UPDATE for internal PINs
                            foreach ($candPins as $cPin) {
                                if ($cPin !== $targetPin && $cPin > 0) {
                                    $cmd = "DATA UPDATE fingertmp\tPIN={$cPin}\tFID={$mfid}\tSize={$size}\tValid={$valid}\tTMP={$tmp}";
                                    $this->commandService->queueCommand($targetDev->serial_number, $cmd);
                                    $cmdCount++;
                                }
                            }
                        }
                    }
                }

                // 4. Queue ADMS deletion for all ghost slots across ALL candidate PINs (badge PIN + internal terminal PIN)
                foreach ($ghostFids as $gfid) {
                    foreach ($candPins as $cPin) {
                        $cmd = "DATA DELETE FINGERTMP\tPIN={$cPin}\tFID={$gfid}";
                        $this->commandService->queueCommand($targetDev->serial_number, $cmd);
                        $cmdCount++;
                    }
                }

                $totalCommands += $cmdCount;
                $pinNote = count($candPins) > 1 ? " (PINs: " . implode(', ', $candPins) . ")" : "";
                $this->line(" • Queued {$cmdCount} sync/fix command(s){$pinNote} for {$targetDev->device_name} ({$targetDev->serial_number})");
            }

            $this->newLine();
            $this->info("✅ Successfully queued {$totalCommands} synchronization command(s) across {$deviceCount} device(s).");
            $this->line("   Terminals will download missing templates, enforce 24/7 access (Grp=1, TZ=1), and purge ghost slots across internal & badge PINs upon their next poll cycle.");
            return 0;
        }

        // 4. Ghost Cleanup Action (--clean)
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
                if (!$targetDev->canReceiveSync()) {
                    continue;
                }
                $ghostFids = $target['ghost_fids'];
                $candPins = $target['candidate_pins'] ?? [$pin];
                $tad = $target['tad'];
                $allGhosts = $target['all_ghosts'];

                // Instant SOAP wipe if all templates on terminal are ghosts
                if ($tad && $allGhosts) {
                    try {
                        foreach ($candPins as $cPin) {
                            $tad->delete_template(['pin' => $cPin]);
                        }
                        $this->line(" • <fg=green>[INSTANT SOAP WIPE]</> Cleared all templates for PIN(s) " . implode(', ', $candPins) . " on {$targetDev->device_name} ({$targetDev->serial_number})");
                    } catch (\Throwable $th) {
                        // Fallback to ADMS delete
                    }
                }

                // Queue ADMS DATA DELETE FINGERTMP for each ghost slot across all candidate PINs
                foreach ($ghostFids as $gfid) {
                    foreach ($candPins as $cPin) {
                        $cmd = "DATA DELETE FINGERTMP\tPIN={$cPin}\tFID={$gfid}";
                        $this->commandService->queueCommand($targetDev->serial_number, $cmd);
                        $purgedCommands++;
                    }
                }

                $this->line(" • Queued " . (count($ghostFids) * count($candPins)) . " ADMS deletion command(s) (FID " . implode(',', $ghostFids) . ") across PIN(s) " . implode(', ', $candPins) . " for {$targetDev->device_name} ({$targetDev->serial_number})");
            }

            $this->newLine();
            $this->info("✅ Successfully queued {$purgedCommands} ghost slot deletion command(s) across {$deviceCount} device(s).");
            $this->line("   Terminals will purge these ghost slots immediately upon their next poll cycle.");
            return 0;
        }

        // 5. Diagnostics & Recommendations
        $this->newLine();

        if (count($detectedAlgos) > 1) {
            $algoParts = [];
            foreach ($detectedAlgos as $alg => $count) {
                $algoParts[] = "{$count} device(s) on {$alg}";
            }
            $this->warn("⚠️  ALGORITHM DIVERGENCE DETECTED: Terminals run mixed ZKFinger algorithms (" . implode(', ', $algoParts) . ").");
            $this->line("   Fingerprint templates are binary-incompatible across different algorithm generations (e.g. v9 vs v10).");
            $this->line("   A template enrolled on a v10 device cannot be loaded or matched by a v9 device, which can cause missing slots.\n");
        }

        if ($hasMissingSlots) {
            $this->warn("⚠️  SYNC DISCREPANCY: Some terminals are missing templates from the DB (e.g. Slot 3).");
            if (count($detectedAlgos) > 1) {
                $this->line("   <fg=yellow;options=bold>Note:</> Verify that target terminals support the algorithm version of the template in DB.");
            }
            $this->line("   To automatically push missing templates to all devices, run with <fg=yellow>--fix</>:");
            $this->line("   <fg=yellow>php artisan biometrics:check-device " . ($deviceSn ? "{$pin} {$deviceSn}" : "{$pin} --all-devices") . " --fix</>\n");
        } elseif ($hasGhostSlots) {
            $this->warn("⚠️  GHOST FINGERPRINTS DETECTED: Physical terminals have extra ghost slots enrolled that do not exist in the database!");
            $this->line("   To purge all detected ghost slots automatically, run with <fg=yellow>--clean</> (or <fg=yellow>--fix</>):");
            $this->line("   <fg=yellow>php artisan biometrics:check-device " . ($deviceSn ? "{$pin} {$deviceSn}" : "{$pin} --all-devices") . " --clean</>\n");
        } elseif (!empty($devicesToFix)) {
            $this->warn("⚠️  DEVICE DISCREPANCY DETECTED: Device has invalid timezone or user settings.");
            $this->line("   To automatically re-provision, run with <fg=yellow>--fix</>:");
            $this->line("   <fg=yellow>php artisan biometrics:check-device " . ($deviceSn ? "{$pin} {$deviceSn}" : "{$pin} --all-devices") . " --fix</>\n");
        } else {
            $this->info("✅ All online terminals are 100% synchronized with the database masterlist for PIN {$pin}.");
        }

        return 0;
    }

    /**
     * Inspect multiple devices concurrently using curl_multi to avoid sequential timeouts.
     */
    protected function inspectDevices($devices, ?Biometrics $bioUser, int $pin, array $dbSlots, int $timeout = 2): array
    {
        $entries = [];
        foreach ($devices as $index => $dev) {
            if ($dev->is_hrbliz) {
                $deviceTargetPin = ($bioUser && !empty($bioUser->hrbliz_biometric_id))
                    ? (int)$bioUser->hrbliz_biometric_id
                    : ($bioUser ? null : $pin);
            } else {
                $deviceTargetPin = $bioUser ? (int)$bioUser->biometric_id : $pin;
            }

            $inspectPin = $deviceTargetPin ?? $pin;
            $entries[$index] = [
                'device' => $dev,
                'target_pin' => $deviceTargetPin,
                'inspect_pin' => $inspectPin,
                'row' => $this->buildEmptyRow($dev, $inspectPin),
            ];
        }

        // Single device can use inspectDevice directly (with timeout respected)
        if (count($entries) === 1) {
            foreach ($entries as $idx => $entry) {
                $entries[$idx]['row'] = $this->inspectDevice($entry['device'], $entry['inspect_pin'], $dbSlots, $timeout);
            }
            return array_values($entries);
        }

        // 1. Parallel reachability probe across all valid IPs
        $probeRequests = [];
        foreach ($entries as $idx => $entry) {
            $dev = $entry['device'];
            $ip = $dev->ip_address ?? '0.0.0.0';
            if (empty($ip) || $ip === '0.0.0.0' || ($ip === '127.0.0.1' && !app()->runningUnitTests())) {
                continue;
            }
            $soapPort = (int)($dev->soap_port ?: 80);
            $probeRequests[$idx] = [
                'url' => "http://{$ip}:{$soapPort}/",
                'method' => 'GET',
                'timeout' => $timeout,
            ];
        }

        if (empty($probeRequests)) {
            return array_values($entries);
        }

        $probeResults = $this->executeCurlMulti($probeRequests);
        $onlineIndices = [];
        foreach ($probeResults as $idx => $res) {
            if (!empty($res['body'])) {
                $onlineIndices[] = $idx;
            }
        }

        if (empty($onlineIndices)) {
            return array_values($entries);
        }

        // 2. Parallel GetOption (~ZKFPVersion) for all online devices
        $algoRequests = [];
        foreach ($onlineIndices as $idx) {
            $dev = $entries[$idx]['device'];
            $comKey = (int)($dev->com_key ?? 0);
            $algoRequests[$idx] = $this->buildSoapCurlRequest(
                $dev,
                "<GetOption><ArgComKey>{$comKey}</ArgComKey><Arg><Name>~ZKFPVersion</Name></Arg></GetOption>",
                $timeout
            );
        }
        $algoResults = $this->executeCurlMulti($algoRequests);

        $algos = [];
        $fallbackAlgoRequests = [];
        foreach ($onlineIndices as $idx) {
            $val = $this->parseOptionValue($algoResults[$idx]['body'] ?? '');
            if ($val !== null) {
                $algos[$idx] = $this->normalizeAlgoVersion($val);
            } else {
                $dev = $entries[$idx]['device'];
                $comKey = (int)($dev->com_key ?? 0);
                $fallbackAlgoRequests[$idx] = $this->buildSoapCurlRequest(
                    $dev,
                    "<GetOption><ArgComKey>{$comKey}</ArgComKey><Arg><Name>ZKFPVersion</Name></Arg></GetOption>",
                    $timeout
                );
            }
        }

        if (!empty($fallbackAlgoRequests)) {
            $fallbackAlgoResults = $this->executeCurlMulti($fallbackAlgoRequests);
            foreach ($fallbackAlgoResults as $idx => $res) {
                $val = $this->parseOptionValue($res['body'] ?? '');
                $algos[$idx] = $val !== null ? $this->normalizeAlgoVersion($val) : 'Unknown';
            }
        }

        // 3. Parallel GetUserInfo for all online devices
        $userRequests = [];
        foreach ($onlineIndices as $idx) {
            $dev = $entries[$idx]['device'];
            $comKey = (int)($dev->com_key ?? 0);
            $inspectPin = $entries[$idx]['inspect_pin'];
            $userRequests[$idx] = $this->buildSoapCurlRequest(
                $dev,
                "<GetUserInfo><ArgComKey>{$comKey}</ArgComKey><Arg><PIN>{$inspectPin}</PIN></Arg></GetUserInfo>",
                $timeout
            );
        }
        $userResults = $this->executeCurlMulti($userRequests);

        $userRows = [];
        $candidatePinsMap = [];
        $templateRequestsPrimary = [];

        foreach ($onlineIndices as $idx) {
            $dev = $entries[$idx]['device'];
            $inspectPin = $entries[$idx]['inspect_pin'];
            $algoVersion = $algos[$idx] ?? 'Unknown';

            if ($algoVersion !== 'Unknown' && $dev->fp_version !== $algoVersion) {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'fp_version')) {
                        $dev->update(['fp_version' => $algoVersion]);
                    }
                } catch (\Throwable) {
                    // Safe fallback if column is not yet present on active DB
                }
            }

            $uBody = $userResults[$idx]['body'] ?? '';
            $userRow = $this->parseUserInfoRow($uBody);
            if (empty($userRow)) {
                $tad = $this->createTadInstance($dev, $timeout);
                $entries[$idx]['row'] = $this->buildMissingUserRow($dev, $inspectPin, $algoVersion, $dbSlots, $tad);
                continue;
            }

            $userRows[$idx] = $userRow;
            $devicePin1 = isset($userRow['PIN']) ? (int)$userRow['PIN'] : null;
            $devicePin2 = isset($userRow['PIN2']) ? (int)$userRow['PIN2'] : null;
            $candidatePins = array_values(array_unique(array_filter([$inspectPin, $devicePin1, $devicePin2])));
            $candidatePinsMap[$idx] = $candidatePins;

            if (!empty($candidatePins[0]) && $candidatePins[0] > 0) {
                $comKey = (int)($dev->com_key ?? 0);
                $templateRequestsPrimary[$idx] = $this->buildSoapCurlRequest(
                    $dev,
                    "<GetUserTemplate><ArgComKey>{$comKey}</ArgComKey><Arg><PIN>{$candidatePins[0]}</PIN></Arg></GetUserTemplate>",
                    $timeout
                );
            }
        }

        // 4. Parallel GetUserTemplate for primary candidate PIN (and secondary PINs if present)
        $templateResultsPrimary = !empty($templateRequestsPrimary) ? $this->executeCurlMulti($templateRequestsPrimary) : [];
        $deviceSlotsMap = [];
        $needsFallbackMap = [];
        $templateRequestsSecondary = [];

        foreach ($userRows as $idx => $userRow) {
            $deviceSlotsMap[$idx] = [];
            $needsFallbackMap[$idx] = false;
            $candidatePins = $candidatePinsMap[$idx] ?? [];

            if (isset($templateResultsPrimary[$idx])) {
                $parsed = $this->parseBulkTemplatesResponse($templateResultsPrimary[$idx]['body'] ?? '');
                if ($parsed['succeeded']) {
                    $deviceSlotsMap[$idx] = $parsed['slots'];
                } else {
                    $needsFallbackMap[$idx] = true;
                }
            }

            if (count($candidatePins) > 1 && $candidatePins[1] > 0) {
                $dev = $entries[$idx]['device'];
                $comKey = (int)($dev->com_key ?? 0);
                $templateRequestsSecondary[$idx] = $this->buildSoapCurlRequest(
                    $dev,
                    "<GetUserTemplate><ArgComKey>{$comKey}</ArgComKey><Arg><PIN>{$candidatePins[1]}</PIN></Arg></GetUserTemplate>",
                    $timeout
                );
            }
        }

        if (!empty($templateRequestsSecondary)) {
            $templateResultsSecondary = $this->executeCurlMulti($templateRequestsSecondary);
            foreach ($templateResultsSecondary as $idx => $res) {
                $parsed = $this->parseBulkTemplatesResponse($res['body'] ?? '');
                if ($parsed['succeeded']) {
                    foreach ($parsed['slots'] as $fid => $size) {
                        $deviceSlotsMap[$idx][$fid] = $size;
                    }
                } else {
                    $needsFallbackMap[$idx] = true;
                }
            }
        }

        // 5. Assemble final rows for devices with user records
        foreach ($userRows as $idx => $userRow) {
            $dev = $entries[$idx]['device'];
            $inspectPin = $entries[$idx]['inspect_pin'];
            $algoVersion = $algos[$idx] ?? 'Unknown';
            $candidatePins = $candidatePinsMap[$idx];
            $tad = $this->createTadInstance($dev, $timeout);
            $deviceSlots = $deviceSlotsMap[$idx];

            if (!empty($needsFallbackMap[$idx]) && empty($deviceSlots)) {
                $deviceSlots = $this->fetchDeviceTemplates($tad, $candidatePins, $dev);
            }

            $entries[$idx]['row'] = $this->buildInspectedRow(
                $dev,
                $inspectPin,
                $algoVersion,
                $userRow,
                $candidatePins,
                $deviceSlots,
                $dbSlots,
                $tad
            );
        }

        return array_values($entries);
    }

    /**
     * Build a default offline/empty row for a device.
     */
    protected function buildEmptyRow(Devices $device, int $pin): array
    {
        return [
            'name' => $device->device_name ?? 'Unknown',
            'sn' => $device->serial_number,
            'ip' => $device->ip_address ?? '0.0.0.0',
            'algo' => '<fg=gray>-</>',
            'connection' => '<fg=red>OFFLINE</>',
            'user' => '-',
            'fingers' => '-',
            'status' => '<fg=red>OFFLINE</>',
            '_has_ghost' => false,
            '_ghost_fids' => [],
            '_missing_fids' => [],
            '_candidate_pins' => [$pin],
            '_tad' => null,
            '_all_ghosts' => false,
            '_status' => 'OFFLINE',
            '_algo' => 'Unknown',
        ];
    }

    protected function createTadInstance(Devices $device, int $timeout = 2)
    {
        $options = [
            'ip' => $device->ip_address ?? '0.0.0.0',
            'com_key' => (int)($device->com_key ?? 0),
            'soap_port' => (int)($device->soap_port ?: 80),
            'udp_port' => (int)($device->udp_port ?: 4370),
            'connection_timeout' => $timeout,
            'encoding' => 'utf-8',
        ];

        return (new TADFactory($options))->get_instance();
    }

    protected function buildSoapCurlRequest(Devices $device, string $xmlBody, int $timeout): array
    {
        $ip = $device->ip_address ?? '0.0.0.0';
        $soapPort = (int)($device->soap_port ?: 80);
        $payload = '<?xml version="1.0" encoding="utf-8" standalone="no"?>'
            . trim(str_replace(["\n", "\r"], '', $xmlBody));

        return [
            'url' => "http://{$ip}:{$soapPort}/iWsService",
            'method' => 'POST',
            'body' => $payload,
            'headers' => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
            ],
            'timeout' => $timeout,
        ];
    }

    /**
     * Execute multiple HTTP requests concurrently via curl_multi.
     */
    protected function executeCurlMulti(array $requests): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($requests as $key => $req) {
            $ch = curl_init($req['url']);
            $timeout = max(1, (int)($req['timeout'] ?? 2));
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
            ];

            if (($req['method'] ?? 'GET') === 'POST') {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = $req['body'] ?? '';
                if (!empty($req['headers'])) {
                    $opts[CURLOPT_HTTPHEADER] = $req['headers'];
                }
            }

            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active && curl_multi_select($mh, 0.2) === -1) {
                usleep(10000);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $err = curl_error($ch);
            $results[$key] = [
                'body' => is_string($body) ? $body : '',
                'error' => $err,
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    protected function parseOptionValue(string $response): ?string
    {
        if (empty($response)) {
            return null;
        }
        try {
            $arr = (new \TADPHP\TADResponse($response, 'utf-8'))->to_array();
            $val = $arr['Row']['Value'] ?? null;
            if (is_array($val) && empty($val)) {
                return null;
            }
            return $val ? trim((string)$val) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeAlgoVersion(string $cleanVal): string
    {
        if ($cleanVal === '10' || $cleanVal === '10.0') {
            return 'v10';
        }
        if ($cleanVal === '9' || $cleanVal === '9.0') {
            return 'v9';
        }
        return "v{$cleanVal}";
    }

    protected function parseUserInfoRow(string $response): ?array
    {
        if (empty($response)) {
            return null;
        }
        try {
            $arr = (new \TADPHP\TADResponse($response, 'utf-8'))->to_array();
            $row = $arr['Row'] ?? null;
            return !empty($row) && is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function parseBulkTemplatesResponse(string $resp): array
    {
        if (empty($resp) || !str_contains($resp, 'GetUserTemplateResponse')) {
            return ['succeeded' => false, 'slots' => []];
        }

        $slots = [];
        if (preg_match_all('/<FingerID>(\d+)<\/FingerID>.*?<(?:Size>(\d+)<\/Size>.*?)?<(?:Template|TMP)>(.*?)<\/(?:Template|TMP)>/s', $resp, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $fid = (int)$m[1];
                $template = trim($m[3]);
                if (!empty($template)) {
                    $size = !empty($m[2]) ? (int)$m[2] : strlen($template);
                    $slots[$fid] = $size;
                }
            }
        }

        return ['succeeded' => true, 'slots' => $slots];
    }

    protected function formatAlgoDisplay(string $algoVersion): string
    {
        return match ($algoVersion) {
            'v10' => '<fg=green>v10</>',
            'v9'  => '<fg=yellow>v9</>',
            default => "<fg=gray>{$algoVersion}</>",
        };
    }

    protected function buildMissingUserRow(Devices $device, int $pin, string $algoVersion, array $dbSlots, $tad): array
    {
        return [
            'name' => $device->device_name ?? 'Unknown',
            'sn' => $device->serial_number,
            'ip' => $device->ip_address ?? '0.0.0.0',
            'algo' => $this->formatAlgoDisplay($algoVersion),
            'connection' => '<fg=green>ONLINE</>',
            'user' => '<fg=red>NOT_FOUND</>',
            'fingers' => '<fg=yellow>None</>',
            'status' => '<fg=red>MISSING_USER</>',
            '_has_ghost' => false,
            '_ghost_fids' => [],
            '_missing_fids' => array_keys($dbSlots),
            '_candidate_pins' => [$pin],
            '_tad' => $tad,
            '_all_ghosts' => false,
            '_status' => 'MISSING_USER',
            '_algo' => $algoVersion,
        ];
    }

    protected function buildInspectedRow(
        Devices $device,
        int $pin,
        string $algoVersion,
        array $userRow,
        array $candidatePins,
        array $deviceSlots,
        array $dbSlots,
        $tad
    ): array {
        $privilege = (int)($userRow['Privilege'] ?? 0);
        $userRoleLabel = ($privilege === 14) ? 'SuperAdmin' : ($privilege === 1 ? 'Admin' : 'User');
        $group = (int)($userRow['Group'] ?? 1);
        $tz1 = (int)($userRow['TZ1'] ?? 1);
        $isTimezoneValid = ($group === 1 && $tz1 === 1);

        $devicePin1 = isset($userRow['PIN']) ? (int)$userRow['PIN'] : null;
        $tzTag = $isTimezoneValid ? "<fg=green>Grp:{$group} TZ:{$tz1}</>" : "<fg=red;options=bold>Grp:{$group} TZ:{$tz1} (INVALID)</>";
        $pinTag = ($devicePin1 && $devicePin1 !== $pin) ? " <fg=gray>IntPIN:{$devicePin1}</>" : '';
        $userDisplay = "<fg=green>YES</>{$pinTag} ({$userRoleLabel}, {$tzTag})";

        $fingerLabels = [];
        foreach ($deviceSlots as $slot => $size) {
            $label = self::FINGER_NAMES[$slot] ?? "Slot {$slot}";
            $fingerLabels[] = "Slot {$slot} ({$label})";
        }
        $fingersDisplay = !empty($fingerLabels) ? implode(', ', $fingerLabels) : '<fg=yellow>None</>';

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

        if (!$isTimezoneValid) {
            $statusCol = "<fg=red;options=bold>INVALID_TIME_PERIOD (Grp:{$group}, TZ:{$tz1})</>";
            $statusCode = 'INVALID_TIME_PERIOD';
        } elseif (!empty($ghostFids) && !empty($missingFids)) {
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
            'name' => $device->device_name ?? 'Unknown',
            'sn' => $device->serial_number,
            'ip' => $device->ip_address ?? '0.0.0.0',
            'algo' => $this->formatAlgoDisplay($algoVersion),
            'connection' => '<fg=green>ONLINE</>',
            'user' => $userDisplay,
            'fingers' => $fingersDisplay,
            'status' => $statusCol,
            '_has_ghost' => $hasGhost,
            '_ghost_fids' => $ghostFids,
            '_missing_fids' => $missingFids,
            '_candidate_pins' => $candidatePins,
            '_tad' => $tad,
            '_all_ghosts' => $allGhosts,
            '_status' => $statusCode,
            '_algo' => $algoVersion,
        ];
    }

    /**
     * Connect directly to a physical terminal and inspect user info, fingerprint algorithm, and finger slots 0-9.
     */
    protected function inspectDevice(Devices $device, int $pin, array $dbSlots, int $timeout = 2): array
    {
        $deviceName = $device->device_name ?? 'Unknown';
        $deviceSn = $device->serial_number;
        $ip = $device->ip_address ?? '0.0.0.0';

        $emptyRow = $this->buildEmptyRow($device, $pin);

        if (empty($ip) || $ip === '0.0.0.0' || ($ip === '127.0.0.1' && !app()->runningUnitTests())) {
            return $emptyRow;
        }

        try {
            $tad = $this->createTadInstance($device, $timeout);

            if (!$tad->is_alive()) {
                return $emptyRow;
            }

            // 1. Detect Device Algorithm (ZKFinger 10.0 vs 9.0)
            $algoVersion = $this->detectDeviceAlgorithm($tad);
            if ($algoVersion !== 'Unknown' && $device->fp_version !== $algoVersion) {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'fp_version')) {
                        $device->update(['fp_version' => $algoVersion]);
                    }
                } catch (\Throwable) {
                    // Safe fallback if column is not yet present on active DB
                }
            }

            // 2. Fetch User Info
            $uRes = $tad->get_user_info(['pin' => $pin]);
            $uArr = $uRes->to_array();
            $userRow = $uArr['Row'] ?? null;

            if (empty($userRow)) {
                return $this->buildMissingUserRow($device, $pin, $algoVersion, $dbSlots, $tad);
            }

            $devicePin1 = isset($userRow['PIN']) ? (int)$userRow['PIN'] : null;
            $devicePin2 = isset($userRow['PIN2']) ? (int)$userRow['PIN2'] : null;
            $candidatePins = array_values(array_unique(array_filter([$pin, $devicePin1, $devicePin2])));

            // 3. Scan Finger Slots (via bulk query and candidate PIN resolution)
            $deviceSlots = $this->fetchDeviceTemplates($tad, $candidatePins, $device);

            return $this->buildInspectedRow(
                $device,
                $pin,
                $algoVersion,
                $userRow,
                $candidatePins,
                $deviceSlots,
                $dbSlots,
                $tad
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('device_logs')->error("inspectDevice error for {$deviceName} ({$deviceSn}): " . $e->getMessage());
            $emptyRow['status'] = '<fg=red>ERROR: ' . substr($e->getMessage(), 0, 45) . '</>';
            return $emptyRow;
        }
    }

    /**
     * Query templates across candidate PINs (internal PIN vs employee PIN2).
     * Attempts fast bulk query first, falling back to per-slot scanning.
     */
    protected function fetchDeviceTemplates($tad, array $candidatePins, Devices $device): array
    {
        $deviceSlots = [];
        $ip = $device->ip_address ?? '0.0.0.0';
        $comKey = (int)($device->com_key ?? 0);

        foreach ($candidatePins as $cPin) {
            if ($cPin <= 0) {
                continue;
            }

            $bulkSucceeded = false;
            $soapPort = (int)($device->soap_port ?: 80);

            // 1. Bulk SOAP query for PIN (retrieves all slots in one instantaneous call)
            if (!empty($ip) && $ip !== '0.0.0.0' && ($ip !== '127.0.0.1' || app()->runningUnitTests())) {
                try {
                    $soapClient = new \SoapClient(null, [
                        'location' => "http://{$ip}:{$soapPort}/iWsService",
                        'uri' => 'http://www.zksoftware/Service/message/',
                        'connection_timeout' => 2,
                        'exceptions' => true,
                    ]);

                    $xml = "<GetUserTemplate><ArgComKey>{$comKey}</ArgComKey><Arg><PIN>{$cPin}</PIN></Arg></GetUserTemplate>";
                    $resp = $soapClient->__doRequest($xml, "http://{$ip}:{$soapPort}/iWsService", '', SOAP_1_1);

                    $parsed = $this->parseBulkTemplatesResponse((string)$resp);
                    if ($parsed['succeeded']) {
                        $bulkSucceeded = true;
                        foreach ($parsed['slots'] as $fid => $size) {
                            $deviceSlots[$fid] = $size;
                        }
                    }
                } catch (\Throwable) {
                    // Fall back to TAD below
                }
            }

            // 2. If bulk query failed/errored, fallback to per-slot TAD queries
            if (!$bulkSucceeded) {
                for ($slot = 0; $slot <= 9; $slot++) {
                    if (isset($deviceSlots[$slot])) {
                        continue;
                    }

                    try {
                        $tRes = $tad->get_user_template(['pin' => $cPin, 'finger_id' => $slot]);
                        $tArr = $tRes->to_array();
                        if (!empty($tArr['Row'])) {
                            $rows = isset($tArr['Row'][0]) ? $tArr['Row'] : [$tArr['Row']];
                            foreach ($rows as $r) {
                                $template = $r['Template'] ?? $r['TMP'] ?? null;
                                if (!empty($template)) {
                                    $fid = (int)($r['FingerID'] ?? $r['Finger_ID'] ?? $r['FID'] ?? $slot);
                                    $size = $r['Size'] ?? strlen($template);
                                    $deviceSlots[$fid] = $size;
                                }
                            }
                        }
                    } catch (\Throwable) {
                        // Slot is empty
                    }
                }
            }
        }

        return $deviceSlots;
    }

    /**
     * Query device for fingerprint algorithm version (~ZKFPVersion).
     */
    protected function detectDeviceAlgorithm($tad): string
    {
        try {
            $optRes = $tad->get_option(['option_name' => '~ZKFPVersion']);
            $optArr = $optRes->to_array();
            $val = $optArr['Row']['Value'] ?? null;
            if (is_array($val) && empty($val)) {
                $val = null;
            }

            if (!$val) {
                $optRes2 = $tad->get_option(['option_name' => 'ZKFPVersion']);
                $optArr2 = $optRes2->to_array();
                $val = $optArr2['Row']['Value'] ?? null;
                if (is_array($val) && empty($val)) {
                    $val = null;
                }
            }

            if (!$val && method_exists($tad, 'get_fingerprint_algorithm')) {
                $algoRes = $tad->get_fingerprint_algorithm();
                $algoArr = $algoRes->to_array();
                $val = $algoArr['Row']['Value'] ?? null;
            }

            if ($val) {
                $cleanVal = trim((string)$val);
                if ($cleanVal === '10' || $cleanVal === '10.0') {
                    return 'v10';
                }
                if ($cleanVal === '9' || $cleanVal === '9.0') {
                    return 'v9';
                }
                return "v{$cleanVal}";
            }
        } catch (\Throwable) {
            // Unable to detect
        }

        return 'Unknown';
    }
}
