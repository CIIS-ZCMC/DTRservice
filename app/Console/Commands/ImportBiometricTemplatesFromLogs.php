<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use App\Services\BiometricSyncService;
use App\Services\ZkPushParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportBiometricTemplatesFromLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:import-from-logs 
                            {--pin= : Import templates for a specific biometric ID / PIN only}
                            {--sync-devices : Queue synchronization commands for all active connected devices}
                            {--file= : Specific log file path to scan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan device logs to recover and import dropped fingerprint templates into biometrics table and sync to devices';

    public function __construct(protected BiometricSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $targetPin = $this->option('pin');
        $syncDevices = $this->option('sync-devices');
        $customFile = $this->option('file');

        $files = [];
        if ($customFile) {
            if (!file_exists($customFile)) {
                $this->error("File not found: {$customFile}");
                return 1;
            }
            $files[] = $customFile;
        } else {
            $files = glob(storage_path('logs/device_logs*.log')) ?: [];
            if (empty($files) && file_exists(storage_path('logs/device_logs.log'))) {
                $files[] = storage_path('logs/device_logs.log');
            }
        }

        if (empty($files)) {
            $this->warn('No device_logs.log files found to scan.');
            return 0;
        }

        $this->info("Scanning " . count($files) . " log file(s)...");

        $templatesFound = [];

        foreach ($files as $file) {
            $this->line("Reading " . basename($file) . " (" . round(filesize($file) / 1024 / 1024, 2) . " MB)...");
            $content = file_get_contents($file);

            // Pattern for FP PIN=...
            preg_match_all('/FP PIN=(\d+)(?:\\\\t|\t)FID=(\d+)(?:\\\\t|\t)Size=(\d+)(?:\\\\t|\t)Valid=(\d+)(?:\\\\t|\t)TMP=([^\r\n"\\s]+)/', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $m) {
                $pin = (int)$m[1];
                $fid = (string)$m[2];
                $size = (string)$m[3];
                $valid = (string)$m[4];
                $tmp = (string)$m[5];

                if ($targetPin !== null && (int)$targetPin !== $pin) {
                    continue;
                }

                $key = "{$pin}-{$fid}";
                $templatesFound[$key] = [
                    'pin' => $pin,
                    'fid' => $fid,
                    'size' => $size,
                    'valid' => $valid,
                    'tmp' => $tmp,
                ];
            }
        }

        $count = count($templatesFound);
        $this->info("Found {$count} unique fingerprint template(s) to process.");

        if ($count === 0) {
            return 0;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $importedCount = 0;
        $syncedDeviceCommands = 0;
        $updatedPins = [];

        foreach ($templatesFound as $item) {
            $pin = $item['pin'];
            $fid = $item['fid'];
            $size = $item['size'];
            $valid = $item['valid'];
            $tmp = $item['tmp'];

            Biometrics::saveFingerprintTemplate($pin, $fid, $size, $valid, $tmp);
            $importedCount++;
            $updatedPins[$pin] = true;

            if ($syncDevices) {
                $syncedDeviceCommands += $this->syncService->syncBiometricToAll(null, 'FINGERTMP', [
                    'PIN' => $pin,
                    'FID' => $fid,
                    'Size' => $size,
                    'Valid' => $valid,
                    'TMP' => $tmp,
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Successfully imported {$importedCount} template(s) across " . count($updatedPins) . " user(s).");
        if ($syncDevices) {
            $this->info("Queued {$syncedDeviceCommands} sync command(s) for active connected biometric devices.");
        }

        // Summary for target PIN if specified
        if ($targetPin) {
            $user = Biometrics::where('biometric_id', $targetPin)->first();
            if ($user) {
                $this->table(
                    ['Biometric ID', 'Name', 'Templates Count', 'Biometric JSON Preview'],
                    [[
                        $user->biometric_id,
                        $user->name,
                        count(json_decode($user->biometric, true) ?? []),
                        substr($user->biometric, 0, 80) . '...'
                    ]]
                );
            }
        }

        return 0;
    }
}
