<?php

namespace App\Console\Commands;

use App\Models\DeviceLogs;
use App\Models\DeviceLogsHrbliz;
use App\Models\Devices;
use App\Services\ZkPunchForwarderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ZkSyncPunches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zk:sync-punches
                            {--date= : Specific date to sync (YYYY-MM-DD)}
                            {--today : Sync punches for today}
                            {--start-date= : Start date for range sync}
                            {--end-date= : End date for range sync}
                            {--limit=500 : Maximum number of punches to sync}
                            {--device-id= : Filter by specific device ID}
                            {--source=all : Source table: "standard", "hrbliz", or "all"}
                            {--dry-run : Preview punches without sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync / backfill attendance punches from DTRService database to ZKTeco Integration Server (Port 8080)';

    public function handle(ZkPunchForwarderService $service): int
    {
        $targetDate = $this->option('date');
        $isToday = $this->option('today');
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $limit = (int)$this->option('limit') ?: 500;
        $deviceIdFilter = $this->option('device-id');
        $source = strtolower($this->option('source') ?: 'all');
        $dryRun = $this->option('dry-run');

        if ($isToday || (!$targetDate && !$startDate && !$endDate)) {
            $targetDate = now()->format('Y-m-d');
        }

        $allowedDevices = $service->getAllowedDeviceIds();

        $this->info("=== ZKTeco Punch Synchronization ===");
        $this->line("Target Server   : <fg=cyan>{$service->getHost()}</>");
        if ($targetDate) {
            $this->line("Filter Date     : <fg=yellow>{$targetDate}</>");
        } else {
            $this->line("Date Range      : <fg=yellow>{$startDate} to {$endDate}</>");
        }
        $this->line("Source          : <fg=yellow>{$source}</>");
        $this->line("Limit           : <fg=yellow>{$limit}</>");
        if ($deviceIdFilter) {
            $this->line("Device ID CLI   : <fg=yellow>{$deviceIdFilter}</>");
        }
        if (!empty($allowedDevices)) {
            $this->line("Allowed Devices : <fg=yellow>" . implode(', ', $allowedDevices) . "</>");
        }
        if ($dryRun) {
            $this->warn("[DRY-RUN MODE] No punches will be transmitted.");
        }

        // Test connection first if not dry run
        if (!$dryRun) {
            $this->line("Checking server health...");
            $health = $service->checkHealth();
            if (!$health['success']) {
                $this->error("Cannot reach ZKTeco server on {$service->getHost()}: " . $health['error']);
                $this->comment("Run `php artisan zk:test-connection` to diagnose.");
                return 1;
            }
            $this->line("<fg=green>✓ Server is online.</>");
        }

        // Gather logs
        $logs = [];

        // Resolve device name filter if device-id is passed
        $targetDeviceNames = [];
        if ($deviceIdFilter) {
            $dev = Devices::find($deviceIdFilter);
            if ($dev) {
                $targetDeviceNames[] = $dev->device_name;
                $targetDeviceNames[] = $dev->serial_number;
            }
        }

        // 1. Standard DeviceLogs
        if (in_array($source, ['all', 'standard']) && Schema::hasTable('device_logs')) {
            $q = DeviceLogs::query();
            if ($targetDate) {
                $q->where('dtr_date', $targetDate);
            } elseif ($startDate && $endDate) {
                $q->whereBetween('dtr_date', [$startDate, $endDate]);
            }
            if (!empty($targetDeviceNames)) {
                $q->whereIn('device_name', array_filter($targetDeviceNames));
            }

            $standardLogs = $q->orderBy('date_time', 'asc')
                ->limit($limit)
                ->get();

            foreach ($standardLogs as $log) {
                // Device filter check
                if (!$service->shouldForwardForDevice($log->device_name)) {
                    continue;
                }

                $mappedPin = $service->resolveForwardPin($log->biometric_id);

                $logs[] = [
                    'canonical_pin' => (string)$log->biometric_id,
                    'pin'           => $mappedPin,
                    'time'          => $log->date_time,
                    'status'        => (int)($log->status ?? 0),
                    'device_sn'     => $log->device_name ?: 'DTR_SERVICE',
                    'source'        => 'device_logs',
                ];
            }
        }

        // 2. HRBLIZ DeviceLogs
        if (in_array($source, ['all', 'hrbliz']) && Schema::hasTable('device_logs_hrbliz')) {
            $remaining = $limit - count($logs);
            if ($remaining > 0) {
                $q2 = DeviceLogsHrbliz::query();
                if ($targetDate) {
                    $q2->where('dtr_date', $targetDate);
                } elseif ($startDate && $endDate) {
                    $q2->whereBetween('dtr_date', [$startDate, $endDate]);
                }
                if (!empty($targetDeviceNames)) {
                    $q2->whereIn('device_name', array_filter($targetDeviceNames));
                }

                $hrbLogs = $q2->orderBy('date_time', 'asc')
                    ->limit($remaining)
                    ->get();

                foreach ($hrbLogs as $log) {
                    if (!$service->shouldForwardForDevice($log->device_name)) {
                        continue;
                    }

                    $mappedPin = $service->resolveForwardPin($log->biometric_id);

                    $logs[] = [
                        'canonical_pin' => (string)$log->biometric_id,
                        'pin'           => $mappedPin,
                        'time'          => $log->date_time,
                        'status'        => (int)($log->status ?? 0),
                        'device_sn'     => $log->device_name ?: 'DTR_HRBLIZ',
                        'source'        => 'device_logs_hrbliz',
                    ];
                }
            }
        }

        if (empty($logs)) {
            $this->info("No punch logs found matching the specified criteria.");
            return 0;
        }

        $this->line(sprintf("Found <fg=cyan>%d</> punches ready to sync.", count($logs)));

        // Preview sample
        $sample = array_slice($logs, 0, 10);
        $this->table(
            ['Canonical PIN', 'Forwarded PIN (HRBLIZ)', 'Date Time', 'Status', 'Device', 'Source'],
            array_map(fn($item) => [
                $item['canonical_pin'],
                $item['pin'] !== $item['canonical_pin']
                    ? "<fg=green>{$item['pin']} (Mapped)</>"
                    : $item['pin'],
                $item['time'],
                $item['status'],
                $item['device_sn'],
                $item['source'],
            ], $sample)
        );

        if ($dryRun) {
            $this->info("Dry run complete. " . count($logs) . " records previewed.");
            return 0;
        }

        $this->info("Pushing batches to {$service->getHost()}/api/punches...");
        $result = $service->forwardBatch($logs);

        if ($result['success']) {
            $this->newLine();
            $this->line("<fg=green;options=bold>✓ SYNCHRONIZATION COMPLETE</>");
            $this->table(
                ['Total Sent', 'Newly Inserted', 'Duplicates Skipped (Preserved)'],
                [[$result['total'], $result['inserted'], $result['skipped']]]
            );
            $this->info("Punches are now stored in ZKTeco database and ready for legacy payroll on Port 4370.");
            return 0;
        }

        $this->error("Synchronization finished with warnings or errors.");
        $this->table(
            ['Total Sent', 'Inserted', 'Skipped', 'Errors'],
            [[$result['total'], $result['inserted'], $result['skipped'], count($result['errors'])]]
        );
        foreach ($result['errors'] as $err) {
            $this->line("<fg=red>• {$err}</>");
        }

        return 1;
    }
}
