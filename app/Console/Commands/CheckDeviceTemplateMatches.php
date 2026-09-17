<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\BiometricSyncService;
use App\Services\DeviceCommandService;
use Illuminate\Console\Command;
use TADPHP\TADFactory;

class CheckDeviceTemplateMatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:check-device-match 
                            {pin : Employee Biometric ID / PIN to inspect}
                            {device_sn? : Target device serial number}
                            {--all-devices : Query all active registered biometric devices}
                            {--db-only : Inspect database templates only (skip physical device network queries)}
                            {--compare-pin= : Compare directly against another specific PIN on terminal & DB}
                            {--clean : Automatically purge detected matching/ghost slots from physical devices}
                            {--force : Bypass confirmation prompt when cleaning}
                            {--json : Output result in raw JSON format}
                            {--timeout=2 : Connection timeout per device in seconds}';

    /**
     * Command aliases for flexible CLI execution.
     *
     * @var array<string>
     */
    protected $aliases = [
        'biometrics:check-device-matches',
        'biometrics:check-device-duplicate',
        'biometrics:check-device-duplicates',
    ];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect physical biometric terminal(s) in real-time to fetch enrolled fingerprint templates for a PIN and detect identical templates matching other employee PINs (e.g. PIN 1162 slot 9 matching PIN 8084)';

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
        $comparePin = $this->option('compare-pin') !== null ? (int)$this->option('compare-pin') : null;
        $clean = (bool)$this->option('clean');
        $force = (bool)$this->option('force');
        $json = (bool)$this->option('json');
        $dbOnly = (bool)$this->option('db-only');

        // 1. Fetch Central Database Record
        $bioUser = Biometrics::where('biometric_id', $pin)->first();
        $dbTemplates = [];
        $dbSlots = [];

        if ($bioUser && !empty($bioUser->biometric) && $bioUser->biometric !== 'NOT_YET_REGISTERED') {
            $dbTemplates = Biometrics::extractFingerprintTemplates($bioUser->biometric);
            foreach ($dbTemplates as $t) {
                $fid = (string)$t['finger_id'];
                $dbSlots[$fid] = [
                    'finger_id' => $fid,
                    'finger_name' => $t['finger_name'],
                    'size' => $t['size'],
                    'version' => $t['version'],
                    'template' => $t['template'],
                    'hash' => $t['hash'],
                    'source' => 'central_db',
                ];
            }
        }

        // 2. Resolve Target Devices
        if ($dbOnly) {
            $devices = collect([]);
        } elseif ($deviceSn) {
            $device = Devices::where('serial_number', $deviceSn)->first();
            if (!$device) {
                if ($json) {
                    $this->line(json_encode([
                        'success' => false,
                        'error' => "Device with serial number {$deviceSn} not found in database.",
                    ], JSON_PRETTY_PRINT));
                } else {
                    $this->error("Device with serial number {$deviceSn} not found in database.");
                }
                return 1;
            }
            $devices = collect([$device]);
        } else {
            // Check all active devices by default or when --all-devices is passed
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
        }

        // 3. Collect Real-Time Templates from Devices
        $deviceReports = [];
        $collectedTemplates = [];
        $comparePinTemplates = [];
        $onlineDevices = [];

        foreach ($devices as $dev) {
            $inspection = $this->inspectDeviceTemplates($dev, $pin, $dbSlots, $comparePin);
            $deviceReports[] = $inspection['summary_row'];

            if ($inspection['is_online']) {
                $onlineDevices[] = [
                    'device' => $dev,
                    'tad' => $inspection['tad'],
                    'candidate_pins' => $inspection['candidate_pins'],
                ];
            }

            foreach ($inspection['templates'] as $t) {
                $collectedTemplates[] = $t;
            }

            foreach ($inspection['compare_templates'] as $ct) {
                $comparePinTemplates[] = $ct;
            }
        }

        // Also add database templates so we catch templates registered in DB even if device is offline
        foreach ($dbSlots as $fid => $dbTmpl) {
            $alreadyIncluded = false;
            foreach ($collectedTemplates as $ct) {
                if ((string)$ct['finger_id'] === (string)$fid && $ct['template'] === $dbTmpl['template']) {
                    $alreadyIncluded = true;
                    break;
                }
            }
            if (!$alreadyIncluded) {
                $collectedTemplates[] = [
                    'finger_id' => $fid,
                    'finger_name' => $dbTmpl['finger_name'],
                    'template' => $dbTmpl['template'],
                    'size' => $dbTmpl['size'],
                    'version' => $dbTmpl['version'],
                    'hash' => $dbTmpl['hash'],
                    'source' => 'Central Database',
                    'device_sn' => 'DATABASE',
                    'device_name' => 'Central Masterlist',
                    'device_ip' => '-',
                    'is_ghost' => false,
                ];
            }
        }

        // 4. Run Cross-PIN Template Match Search
        $matches = Biometrics::findMatchesForTemplates($collectedTemplates, $pin);

        // If compare PIN was specified, also check direct terminal comparison
        $directCompareMatches = [];
        if ($comparePin !== null) {
            $compareBioUser = Biometrics::where('biometric_id', $comparePin)->first();
            $compareDbTemplates = $compareBioUser && !empty($compareBioUser->biometric) && $compareBioUser->biometric !== 'NOT_YET_REGISTERED'
                ? Biometrics::extractFingerprintTemplates($compareBioUser->biometric)
                : [];

            // Combine compare PIN device + DB templates
            $allComparePool = $comparePinTemplates;
            foreach ($compareDbTemplates as $cdt) {
                $allComparePool[] = [
                    'finger_id' => (string)$cdt['finger_id'],
                    'finger_name' => $cdt['finger_name'],
                    'template' => $cdt['template'],
                    'size' => $cdt['size'],
                    'version' => $cdt['version'],
                    'hash' => $cdt['hash'],
                    'source' => 'Central Database',
                    'device_sn' => 'DATABASE',
                    'device_name' => 'Central Masterlist',
                ];
            }

            foreach ($collectedTemplates as $targetTmpl) {
                foreach ($allComparePool as $compTmpl) {
                    if ($targetTmpl['template'] === $compTmpl['template']) {
                        $directCompareMatches[] = [
                            'target_pin' => $pin,
                            'target_finger_id' => $targetTmpl['finger_id'],
                            'target_finger_name' => $targetTmpl['finger_name'],
                            'target_source' => $targetTmpl['source'],
                            'target_device_sn' => $targetTmpl['device_sn'],
                            'target_device_name' => $targetTmpl['device_name'],
                            'is_ghost_on_device' => $targetTmpl['is_ghost'] ?? false,
                            'matched_pin' => $comparePin,
                            'matched_name' => $compareBioUser?->name ?? "PIN {$comparePin}",
                            'matched_finger_id' => $compTmpl['finger_id'],
                            'matched_finger_name' => $compTmpl['finger_name'],
                            'matched_source' => $compTmpl['source'],
                            'is_same_finger_slot' => ((string)$targetTmpl['finger_id'] === (string)$compTmpl['finger_id']),
                            'algorithm' => $targetTmpl['version'] ?? 'v10',
                            'size' => $targetTmpl['size'],
                            'match_type' => 'DIRECT_PIN_COMPARE_MATCH',
                        ];
                    }
                }
            }
        }

        // 5. Handle JSON Output
        if ($json) {
            $this->line(json_encode([
                'success' => true,
                'target_pin' => $pin,
                'target_name' => $bioUser?->name ?? 'Unknown',
                'devices_scanned_count' => count($devices),
                'total_templates_scanned' => count($collectedTemplates),
                'has_matches' => !empty($matches) || !empty($directCompareMatches),
                'matches_count' => count($matches),
                'matches' => $matches,
                'direct_compare_pin' => $comparePin,
                'direct_compare_matches' => $directCompareMatches,
                'devices' => $deviceReports,
            ], JSON_PRETTY_PRINT));
            return 0;
        }

        // 6. CLI Visual Presentation
        $this->newLine();
        $this->info("========================================================================================");
        $this->info("   BIOMETRIC DEVICE REAL-TIME TEMPLATE MATCH & DUPLICATE INSPECTION");
        $this->info("========================================================================================");
        $this->line("<fg=cyan;options=bold>Target Employee Details:</>");
        $this->line(" • PIN:               <fg=yellow;options=bold>{$pin}</>");
        $this->line(" • Employee Name:     <fg=white;options=bold>" . ($bioUser?->name ?? '<fg=yellow>Not found in biometrics database</>') . "</>");
        $this->line(" • DB Enrolled Slots: " . (!empty($dbSlots) ? implode(', ', array_map(fn($s) => "Slot {$s['finger_id']} ({$s['finger_name']})", $dbSlots)) : '<fg=yellow>None / NOT_YET_REGISTERED</>'));
        if ($comparePin !== null) {
            $compareBioUser = Biometrics::where('biometric_id', $comparePin)->first();
            $this->line(" • Direct Compare:    <fg=magenta;options=bold>PIN {$comparePin}</> (" . ($compareBioUser?->name ?? 'Unknown') . ")");
        }
        $this->newLine();

        // Device Connection & Hardware Slots Table
        $this->line("<fg=cyan;options=bold>Physical Terminals Query Status & Hardware Enrolled Slots:</>");
        $this->table(
            ['Device Name', 'Serial Number', 'IP Address', 'Connection', 'Hardware Enrolled Fingers', 'Ghost Slots (Device Only)'],
            $deviceReports
        );

        // Enrolled Templates Extracted
        $this->newLine();
        $this->line("<fg=cyan;options=bold>Fingerprint Templates Extracted from Terminals & Central Masterlist:</>");
        $tmplRows = [];
        foreach ($collectedTemplates as $t) {
            $ghostTag = !empty($t['is_ghost']) ? '<fg=yellow;options=bold>YES (Ghost Slot)</>' : '<fg=green>NO (In DB)</>';
            $tmplRows[] = [
                "Slot {$t['finger_id']} ({$t['finger_name']})",
                $t['source'],
                strtoupper($t['version'] ?? 'v10'),
                $t['size'] . ' bytes',
                $ghostTag,
                substr($t['template'], 0, 16) . '...' . substr($t['template'], -10),
            ];
        }
        $this->table(['Finger Slot', 'Source (Device/DB)', 'Algorithm', 'Size', 'Ghost on Device?', 'Template Preview'], $tmplRows);

        // 7. Display Match Results
        $allFoundMatches = !empty($directCompareMatches) ? $directCompareMatches : $matches;

        if (empty($allFoundMatches)) {
            $this->newLine();
            $this->info("✅ SUCCESS: No identical fingerprint templates found across other employee PINs.");
            $this->line("All " . count($collectedTemplates) . " template(s) recorded for PIN {$pin} on active terminal(s) and database are completely unique.");
            $this->newLine();
            return 0;
        }

        $this->newLine();
        $this->error("🚨 CRITICAL: IDENTICAL FINGERPRINT TEMPLATE(S) DETECTED ACROSS PINS!");
        $this->line("The following template(s) recorded for <fg=yellow;options=bold>PIN {$pin}</> are <fg=red;options=bold>100% IDENTICAL</> to other employee PIN(s):");
        $this->newLine();

        $matchRows = [];
        $ghostSlotsToClean = [];

        foreach ($allFoundMatches as $m) {
            $sameSlotText = !empty($m['is_same_finger_slot']) ? '<fg=green>YES</>' : '<fg=yellow>NO (Cross-Slot)</>';
            $sourceLabel = $m['target_device_name'] ? "{$m['target_device_name']}" : ($m['target_source'] ?? 'Device');
            $ghostLabel = !empty($m['is_ghost_on_device']) ? '<fg=yellow;options=bold>GHOST ON DEVICE</>' : '<fg=cyan>REGISTERED IN DB</>';

            $matchRows[] = [
                "Slot {$m['target_finger_id']} ({$m['target_finger_name']})",
                $sourceLabel . " ({$ghostLabel})",
                $m['matched_pin'],
                $m['matched_name'],
                "Slot {$m['matched_finger_id']} ({$m['matched_finger_name']})",
                $sameSlotText,
                strtoupper($m['algorithm'] ?? 'v10'),
                '<fg=red;options=bold>100% IDENTICAL</>',
            ];

            // If it's on a device and user might want to clean it
            if (!empty($m['target_device_sn']) && $m['target_device_sn'] !== 'DATABASE') {
                $ghostSlotsToClean[] = [
                    'device_sn' => $m['target_device_sn'],
                    'device_name' => $m['target_device_name'] ?? 'Device',
                    'finger_id' => (int)$m['target_finger_id'],
                    'matched_pin' => $m['matched_pin'],
                ];
            }
        }

        foreach ($allFoundMatches as $m) {
            $this->line(" • <fg=yellow;options=bold>Slot {$m['target_finger_id']} ({$m['target_finger_name']})</> matches <fg=cyan;options=bold>PIN {$m['matched_pin']}</> (<fg=white;options=bold>{$m['matched_name']}</>) on Slot {$m['matched_finger_id']} ({$m['matched_finger_name']})");
        }
        $this->newLine();

        $this->table([
            "Target PIN {$pin} Finger",
            'Detected Location / Source',
            'Matched PIN',
            'Matched Employee Name',
            'Matched Finger Slot',
            'Same Slot?',
            'Algorithm',
            'Match Status',
        ], $matchRows);

        // Root Cause Analysis
        $this->newLine();
        $this->line("<fg=yellow;options=bold>⚠️  Root Cause & Impact Analysis:</>");
        $this->line(" • When a biometric terminal has an identical fingerprint registered under two different PINs (e.g. PIN {$pin} and PIN " . ($allFoundMatches[0]['matched_pin'] ?? 'XXXX') . "),");
        $this->line("   the terminal's hardware 1:N recognition algorithm cannot distinguish between the two employees.");
        $this->line(" • This causes punches to be randomly attributed to the wrong employee or discarded, leading to missing attendance logs.");
        $this->line(" • Possible causes: An employee was mistakenly re-enrolled under a wrong PIN, enrolled another person's finger, or a ghost slot was left on the terminal.");

        // 8. Auto-Clean Action (--clean)
        if ($clean && !empty($ghostSlotsToClean)) {
            $this->newLine();
            $slotCount = count($ghostSlotsToClean);

            if (!$force) {
                if (!$this->confirm("Found {$slotCount} duplicate/ghost template slot(s) on physical terminal(s). Automatically purge them from device(s) now?", true)) {
                    $this->line('Cleanup cancelled by user.');
                    return 0;
                }
            }

            $this->info("Purging duplicate/conflicting slots for PIN {$pin} from physical devices...");
            $purgedCount = 0;

            foreach ($ghostSlotsToClean as $cleanTarget) {
                $sn = $cleanTarget['device_sn'];
                $fid = $cleanTarget['finger_id'];

                // 1. Queue ADMS deletion
                $cmd = "DATA DELETE FINGERTMP\tPIN={$pin}\tFID={$fid}";
                $this->commandService->queueCommand($sn, $cmd);
                $purgedCount++;

                // 2. Instant SOAP delete if terminal is online
                foreach ($onlineDevices as $onDev) {
                    if ($onDev['device']->serial_number === $sn && $onDev['tad']) {
                        try {
                            foreach ($onDev['candidate_pins'] as $cPin) {
                                $onDev['tad']->delete_template(['pin' => $cPin, 'finger_id' => $fid]);
                            }
                            $this->line(" • <fg=green>[INSTANT SOAP PURGE]</> Purged Slot {$fid} for PIN {$pin} on {$sn}");
                        } catch (\Throwable) {
                            // Handled by queued ADMS command
                        }
                    }
                }

                $this->line(" • Queued ADMS deletion for Slot {$fid} on device {$cleanTarget['device_name']} ({$sn})");
            }

            $this->newLine();
            $this->info("✅ Successfully queued {$purgedCount} deletion command(s). The conflicting template slots will be purged from the physical terminals.");
            return 0;
        }

        // Remediation Recommendations
        $this->newLine();
        $this->line("<fg=yellow;options=bold>Recommended Actions to Resolve:</>");
        $this->line(" 1. Automatically purge the conflicting slot(s) from target device(s) using the <fg=cyan>--clean</> flag:");
        $this->line("    <fg=cyan>php artisan biometrics:check-device-match {$pin} " . ($deviceSn ?: '--all-devices') . " --clean</>");
        $this->line(" 2. To permanently delete a specific finger slot from both the central database and all terminals, run:");
        foreach ($allFoundMatches as $m) {
            $this->line("    <fg=cyan>php artisan biometrics:delete-finger {$pin} {$m['target_finger_id']}</>");
        }
        $this->newLine();

        return 0;
    }

    /**
     * Connect to a physical device terminal and extract raw template strings for PIN (slots 0-9).
     *
     * @param Devices $device
     * @param int $pin
     * @param array $dbSlots
     * @param int|null $comparePin
     * @return array
     */
    protected function inspectDeviceTemplates(Devices $device, int $pin, array $dbSlots, ?int $comparePin = null): array
    {
        $deviceName = $device->device_name ?? 'Unknown';
        $deviceSn = $device->serial_number;
        $ip = $device->ip_address ?? '0.0.0.0';

        $emptyResult = [
            'is_online' => false,
            'tad' => null,
            'candidate_pins' => [$pin],
            'templates' => [],
            'compare_templates' => [],
            'summary_row' => [
                'name' => $deviceName,
                'sn' => $deviceSn,
                'ip' => $ip,
                'connection' => '<fg=red>OFFLINE</>',
                'fingers' => '-',
                'ghosts' => '-',
            ],
        ];

        if (empty($ip) || $ip === '0.0.0.0' || ($ip === '127.0.0.1' && !app()->runningUnitTests())) {
            return $emptyResult;
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
                return $emptyResult;
            }

            // Fetch User Info to resolve candidate PINs (internal PIN vs PIN2)
            $uRes = $tad->get_user_info(['pin' => $pin]);
            $uArr = $uRes->to_array();
            $userRow = $uArr['Row'] ?? null;

            $devicePin1 = isset($userRow['PIN']) ? (int)$userRow['PIN'] : null;
            $devicePin2 = isset($userRow['PIN2']) ? (int)$userRow['PIN2'] : null;
            $candidatePins = array_values(array_unique(array_filter([$pin, $devicePin1, $devicePin2])));

            // Fetch device algorithm
            $algo = $device->getFingerprintAlgorithm();

            // Fetch full raw templates from device for target PIN
            $extractedTemplates = $this->fetchRawTemplatesFromDevice($tad, $candidatePins, $device, $dbSlots, $algo);

            // If compare PIN is requested, fetch compare PIN's templates as well
            $compareTemplates = [];
            if ($comparePin !== null) {
                $compareTemplates = $this->fetchRawTemplatesFromDevice($tad, [$comparePin], $device, [], $algo);
            }

            // Format hardware enrolled fingers string
            $fingerLabels = [];
            $ghostLabels = [];
            foreach ($extractedTemplates as $t) {
                $lbl = "Slot {$t['finger_id']} ({$t['finger_name']})";
                $fingerLabels[] = $lbl;
                if (!empty($t['is_ghost'])) {
                    $ghostLabels[] = "<fg=yellow;options=bold>Slot {$t['finger_id']}</>";
                }
            }

            $fingersDisplay = !empty($fingerLabels) ? implode(', ', $fingerLabels) : '<fg=yellow>None</>';
            $ghostsDisplay = !empty($ghostLabels) ? implode(', ', $ghostLabels) : '<fg=green>None</>';

            return [
                'is_online' => true,
                'tad' => $tad,
                'candidate_pins' => $candidatePins,
                'templates' => $extractedTemplates,
                'compare_templates' => $compareTemplates,
                'summary_row' => [
                    'name' => $deviceName,
                    'sn' => $deviceSn,
                    'ip' => $ip,
                    'connection' => '<fg=green>ONLINE</>',
                    'fingers' => $fingersDisplay,
                    'ghosts' => $ghostsDisplay,
                ],
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('device_logs')->error("inspectDeviceTemplates error for {$deviceName} ({$deviceSn}): " . $e->getMessage());
            $emptyResult['summary_row']['connection'] = '<fg=red>ERROR</>';
            return $emptyResult;
        }
    }

    /**
     * Extract raw template payload strings (slots 0-9) from a physical device.
     *
     * @param mixed $tad
     * @param array $candidatePins
     * @param Devices $device
     * @param array $dbSlots
     * @param string $algo
     * @return array
     */
    protected function fetchRawTemplatesFromDevice($tad, array $candidatePins, Devices $device, array $dbSlots, string $algo): array
    {
        $deviceTemplates = [];
        $ip = $device->ip_address ?? '0.0.0.0';
        $comKey = (int)($device->com_key ?? 0);

        foreach ($candidatePins as $cPin) {
            if ($cPin <= 0) {
                continue;
            }

            $bulkFound = false;

            // 1. Bulk SOAP query for PIN (retrieves all slots in one instantaneous call)
            if (!empty($ip) && $ip !== '0.0.0.0' && ($ip !== '127.0.0.1' || app()->runningUnitTests())) {
                try {
                    $soapClient = new \SoapClient(null, [
                        'location' => "http://{$ip}/iWsService",
                        'uri' => 'http://www.zksoftware/Service/message/',
                        'connection_timeout' => 2,
                        'exceptions' => true,
                    ]);

                    $xml = "<GetUserTemplate><ArgComKey>{$comKey}</ArgComKey><Arg><PIN>{$cPin}</PIN></Arg></GetUserTemplate>";
                    $resp = $soapClient->__doRequest($xml, "http://{$ip}/iWsService", '', SOAP_1_1);

                    if (!empty($resp) && preg_match_all('/<FingerID>(\d+)<\/FingerID>.*?<(?:Size>(\d+)<\/Size>.*?)?<(?:Template|TMP)>(.*?)<\/(?:Template|TMP)>/s', $resp, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $m) {
                            $fid = (string)$m[1];
                            $template = trim($m[3]);
                            if (!empty($template)) {
                                $size = !empty($m[2]) ? (int)$m[2] : strlen($template);
                                $fidInt = (int)$fid;
                                $deviceTemplates[$fid] = [
                                    'finger_id' => $fid,
                                    'finger_name' => Biometrics::FINGER_NAMES[$fidInt] ?? "Slot {$fid}",
                                    'template' => $template,
                                    'size' => $size,
                                    'version' => $algo,
                                    'hash' => md5($template),
                                    'source' => "Device: {$device->device_name}",
                                    'device_sn' => $device->serial_number,
                                    'device_name' => $device->device_name,
                                    'device_ip' => $device->ip_address,
                                    'is_ghost' => !isset($dbSlots[$fid]),
                                ];
                                $bulkFound = true;
                            }
                        }
                    }
                } catch (\Throwable) {
                    // Fall back to TAD below
                }
            }

            // 2. If bulk query didn't find templates, fallback to per-slot TAD queries
            if (!$bulkFound) {
                for ($slot = 0; $slot <= 9; $slot++) {
                    $slotStr = (string)$slot;
                    if (isset($deviceTemplates[$slotStr])) {
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
                                    $fid = (string)($r['FingerID'] ?? $r['Finger_ID'] ?? $r['FID'] ?? $slot);
                                    $size = (int)($r['Size'] ?? strlen($template));
                                    $fidInt = (int)$fid;
                                    $deviceTemplates[$fid] = [
                                        'finger_id' => $fid,
                                        'finger_name' => Biometrics::FINGER_NAMES[$fidInt] ?? "Slot {$fid}",
                                        'template' => trim($template),
                                        'size' => $size,
                                        'version' => $algo,
                                        'hash' => md5(trim($template)),
                                        'source' => "Device: {$device->device_name}",
                                        'device_sn' => $device->serial_number,
                                        'device_name' => $device->device_name,
                                        'device_ip' => $device->ip_address,
                                        'is_ghost' => !isset($dbSlots[$fid]),
                                    ];
                                }
                            }
                        }
                    } catch (\Throwable) {
                        // Slot is empty
                    }
                }
            }
        }

        return array_values($deviceTemplates);
    }
}
