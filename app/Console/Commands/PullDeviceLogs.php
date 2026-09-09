<?php

namespace App\Console\Commands;

use App\Models\Devices;
use App\Services\DeviceService;
use Illuminate\Console\Command;

class PullDeviceLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'devices:pull-logs
                            {device_id? : Specific device ID from devices table}
                            {--all : Pull from all active devices}
                            {--date= : Filter logs for a specific date (Y-m-d)}
                            {--start-date= : Filter logs start date (Y-m-d)}
                            {--end-date= : Filter logs end date (Y-m-d)}
                            {--pin= : Filter for a specific biometric ID / PIN}
                            {--resend : Request devices to resend logs via ADMS push command (DATA QUERY ATTLOG)}
                            {--type=DATA QUERY ATTLOG : ADMS command type: "DATA QUERY ATTLOG", "LOG", "BOTH", or "CHECK"}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually pull or request resend of attendance logs from biometric devices and save to database';

    public function __construct(protected DeviceService $deviceService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        @set_time_limit(300);

        $deviceId = $this->argument('device_id');
        $pullAll = $this->option('all');
        $date = $this->option('date');
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $pin = $this->option('pin');
        $isResend = $this->option('resend');
        $cmdType = $this->option('type') ?: 'DATA QUERY ATTLOG';

        $options = array_filter([
            'date' => $date,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'pin' => $pin,
            'type' => $cmdType,
        ], fn($v) => $v !== null && $v !== '');

        if (!$deviceId && !$pullAll) {
            $this->info('No device ID specified. Defaulting to all active devices (--all)...');
            $pullAll = true;
        }

        // Mode 1: Enqueue ADMS Push command (DATA QUERY ATTLOG)
        if ($isResend) {
            if ($deviceId) {
                $this->info("Enqueuing {$cmdType} command for device ID {$deviceId}...");
                $res = $this->deviceService->requestLogResend((int)$deviceId, $options);
                $this->table(
                    ['Device ID', 'Device Name', 'SN', 'Status', 'Start Time', 'End Time', 'Message'],
                    [[
                        $res['device_id'],
                        $res['device_name'],
                        $res['serial_number'] ?? 'N/A',
                        strtoupper($res['status']),
                        $res['start_time'] ?? 'N/A',
                        $res['end_time'] ?? 'N/A',
                        $res['message'] ?? '',
                    ]]
                );
                return ($res['status'] === 'queued') ? 0 : 1;
            }

            $this->info("Enqueuing {$cmdType} command for ALL active devices...");
            $res = $this->deviceService->requestLogResendFromActiveDevices($options);
            $rows = [];
            foreach ($res['devices'] as $d) {
                $rows[] = [
                    $d['device_id'],
                    $d['device_name'],
                    $d['serial_number'] ?? 'N/A',
                    strtoupper($d['status']),
                    $d['start_time'] ?? 'N/A',
                    $d['end_time'] ?? 'N/A',
                    $d['message'] ?? '',
                ];
            }
            $this->table(['ID', 'Device Name', 'SN', 'Status', 'Start Time', 'End Time', 'Info'], $rows);
            $this->info("Queued commands for {$res['devices_queued']} device(s). Skipped: {$res['devices_skipped']}.");
            $this->info("Devices will pick up the command on their next /iclock/getrequest polling cycle and push logs to /iclock/cdata.");
            return 0;
        }

        // Mode 2: Direct SOAP pull (instant)
        if ($deviceId) {
            $this->info("Pulling logs from device ID: {$deviceId}...");
            try {
                $result = $this->deviceService->pullLogsFromDevice((int)$deviceId, $options);
                
                $statusColor = ($result['status'] === 'success') ? 'info' : 'error';
                $this->{$statusColor}("Device: {$result['device_name']} ({$result['ip_address']}) - Status: " . strtoupper($result['status']));
                
                $this->table(
                    ['Device ID', 'Device Name', 'IP', 'Status', 'Total In Device', 'Filtered', 'New Saved', 'Duplicates Skipped'],
                    [[
                        $result['device_id'],
                        $result['device_name'],
                        $result['ip_address'],
                        $result['status'],
                        $result['total_pulled'],
                        $result['filtered_count'],
                        $result['new_saved'],
                        $result['duplicates_skipped'],
                    ]]
                );

                if (!empty($result['sample'])) {
                    $this->info('Sample saved logs:');
                    $this->table(['Biometric ID', 'Date & Time', 'Status'], $result['sample']);
                }

                return ($result['status'] === 'success') ? 0 : 1;
            } catch (\Throwable $e) {
                $this->error('Error pulling logs: ' . $e->getMessage());
                return 1;
            }
        }

        $this->info('Pulling logs from ALL active devices...');
        $startTime = microtime(true);
        $summary = $this->deviceService->pullLogsFromActiveDevices($options);
        $elapsed = round(microtime(true) - $startTime, 2);

        $tableRows = [];
        foreach ($summary['devices'] as $d) {
            $tableRows[] = [
                $d['device_id'],
                $d['device_name'],
                $d['ip_address'],
                strtoupper($d['status']),
                $d['total_pulled'],
                $d['filtered_count'],
                $d['new_saved'],
                $d['duplicates_skipped'],
            ];
        }

        $this->table(
            ['ID', 'Device Name', 'IP', 'Status', 'In Device', 'Filtered', 'New Saved', 'Skipped'],
            $tableRows
        );

        $this->info("Completed in {$elapsed}s. Total Devices: {$summary['total_devices']} (Online: {$summary['online_devices']}, Offline: {$summary['offline_devices']})");
        $this->info("Summary: Pulled: {$summary['total_pulled']}, New Saved: {$summary['total_saved']}, Duplicates Skipped: {$summary['total_skipped']}");

        return 0;
    }
}
