<?php

namespace App\Console\Commands;

use App\Models\Devices;
use App\Services\DeviceService;
use Illuminate\Console\Command;

class ClearDeviceAttendanceLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'devices:clear-logs
                            {device_id? : Specific device ID from devices table}
                            {--all : Clear attendance logs on all active devices}
                            {--method=both : Deletion method: "both" (default), "soap", or "adms"}
                            {--older-than=7 : Only clear devices that have not had logs cleared in X days}
                            {--catch-up : Only target devices needing log clearance based on --older-than}
                            {--force : Bypass confirmation prompt}
                            {--dry-run : Simulate pre-verification without issuing the clear command}
                            {--skip-sync : Skip pre-wipe pull & verification (requires --force)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely clear attendance logs from physical biometric devices with pre-sync verification';

    public function __construct(protected DeviceService $deviceService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        @set_time_limit(300);

        $deviceId = $this->argument('device_id');
        $clearAll = $this->option('all');
        $method = strtolower($this->option('method') ?: 'both');
        $olderThan = (int)($this->option('older-than') ?: 7);
        $catchUp = (bool)$this->option('catch-up');
        $force = (bool)$this->option('force');
        $dryRun = (bool)$this->option('dry-run');
        $skipSync = (bool)$this->option('skip-sync');

        if (!$deviceId && !$clearAll) {
            $this->info('No device ID specified. Defaulting to all active devices (--all)...');
            $clearAll = true;
        }

        if ($dryRun) {
            $this->warn('*** DRY-RUN MODE: Simulating pre-verification and connectivity. No data will be deleted on devices. ***');
        }

        if (!$force && !$dryRun) {
            $warning = $deviceId
                ? "You are about to clear attendance logs on device ID {$deviceId}."
                : "You are about to clear attendance logs on " . ($catchUp ? "devices needing clearance (>= {$olderThan} days)" : "ALL active devices") . ".";

            $confirmed = $this->confirm("{$warning} All punches will be pre-synced to DB first. Do you wish to continue?", false);
            if (!$confirmed) {
                $this->info('Operation cancelled by user.');
                return 0;
            }
        }

        $options = [
            'method' => $method,
            'force' => $force,
            'dry_run' => $dryRun,
            'skip_sync' => $skipSync,
            'catch_up' => $catchUp,
            'older_than' => $olderThan,
        ];

        // Single device mode
        if ($deviceId) {
            $this->info("Processing device ID {$deviceId} (Method: " . strtoupper($method) . ")...");
            $res = $this->deviceService->clearAttendanceLogsFromDevice((int)$deviceId, $options);

            $this->displayResultsTable([$res]);
            $isOk = in_array($res['status'] ?? '', ['success', 'queued', 'dry_run_success', 'skipped_offline']);
            return $isOk ? 0 : 1;
        }

        // Multi-device mode
        $targetDesc = $catchUp
            ? "active devices requiring clearance (older than {$olderThan} days)"
            : "ALL active devices";
        $this->info("Processing {$targetDesc} (Method: " . strtoupper($method) . ")...");

        $batchRes = $this->deviceService->clearAttendanceLogsFromActiveDevices($options);
        $this->displayResultsTable($batchRes['devices'] ?? []);

        $this->info(sprintf(
            "Completed: %d Targeted | %d Cleared/DryRun | %d Queued | %d Skipped Offline | %d Aborted Sync Error | %d Errors",
            $batchRes['total_targeted'] ?? 0,
            $batchRes['cleared_count'] ?? 0,
            $batchRes['queued_count'] ?? 0,
            $batchRes['skipped_offline_count'] ?? 0,
            $batchRes['aborted_sync_count'] ?? 0,
            $batchRes['error_count'] ?? 0
        ));

        return 0;
    }

    /**
     * Format and display execution results in console table.
     */
    protected function displayResultsTable(array $results): void
    {
        $rows = [];
        foreach ($results as $res) {
            $preSyncStr = 'N/A';
            if (isset($res['pre_sync']) && is_array($res['pre_sync'])) {
                $preSyncStr = sprintf(
                    'P:%d S:%d D:%d',
                    $res['pre_sync']['total_pulled'] ?? 0,
                    $res['pre_sync']['new_saved'] ?? 0,
                    $res['pre_sync']['duplicates_skipped'] ?? 0
                );
            }

            $rows[] = [
                $res['device_id'] ?? 'N/A',
                $res['device_name'] ?? 'N/A',
                $res['ip_address'] ?? 'N/A',
                ($res['is_online'] ?? false) ? '<fg=green>ONLINE</>' : '<fg=yellow>OFFLINE</>',
                strtoupper($res['status'] ?? 'UNKNOWN'),
                strtoupper($res['method'] ?? 'N/A'),
                $preSyncStr,
                $res['message'] ?? '',
            ];
        }

        $this->table(
            ['ID', 'Device Name', 'IP', 'Online', 'Status', 'Method', 'Pre-Sync (P/S/D)', 'Info'],
            $rows
        );
    }
}
