<?php

namespace App\Console\Commands;

use App\Services\ZkPunchForwarderService;
use Illuminate\Console\Command;

class ZkTestConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zk:test-connection {--host= : Override ZKTeco server host URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connectivity from DTRService to ZKTeco Inbound Punch API (Port 8080)';

    public function handle(ZkPunchForwarderService $service): int
    {
        $overrideHost = $this->option('host');
        if ($overrideHost) {
            config(['zkteco.host' => $overrideHost]);
            // Re-instantiate with new host
            $service = new ZkPunchForwarderService();
        }

        $host = $service->getHost();
        $enabled = $service->isEnabled() ? '<fg=green>ENABLED</>' : '<fg=yellow>DISABLED</>';
        $allowedDevices = $service->getAllowedDeviceIds();
        $deviceFilterText = empty($allowedDevices) ? 'ALL Devices (No filter)' : implode(', ', $allowedDevices);
        $hrbMapping = config('zkteco.use_hrbliz_id', true) ? '<fg=green>ENABLED (Maps to hrbliz_biometric_id)</>' : '<fg=yellow>DISABLED (Uses canonical biometric_id)</>';

        $this->info("=== Testing ZKTeco Server Connection ===");
        $this->line("Target Host       : <fg=cyan>{$host}</>");
        $this->line("Forward Status    : {$enabled}");
        $this->line("Allowed Device IDs: <fg=yellow>{$deviceFilterText}</>");
        $this->line("HRBLIZ Mapping    : {$hrbMapping}");
        $this->line("Checking health endpoint ({$host}/api/punches)...");

        $health = $service->checkHealth();

        if ($health['success']) {
            $data = $health['data'] ?? [];
            $this->newLine();
            $this->line("<fg=green;options=bold>✓ CONNECTION SUCCESSFUL</> (Latency: {$health['latency_ms']} ms)");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Service', $data['service'] ?? 'N/A'],
                    ['Status', $data['status'] ?? 'N/A'],
                    ['Message', $data['message'] ?? 'N/A'],
                    ['Total Punches Stored', $data['total_punches_stored'] ?? 'N/A'],
                    ['Today Punches', $data['today_punches'] ?? 'N/A'],
                    ['Server Timestamp', $data['timestamp'] ?? 'N/A'],
                ]
            );

            $this->info("DTRService is ready to forward punches to {$host}!");
            return 0;
        }

        $this->newLine();
        $this->error("✗ CONNECTION FAILED (Latency: {$health['latency_ms']} ms)");
        $this->error("Error: " . ($health['error'] ?? 'Unknown connection error'));
        $this->newLine();
        $this->comment("Troubleshooting tips:");
        $this->line(" 1. Check if ZKTeco server is running on {$host}");
        $this->line(" 2. If running co-hosted on 192.168.9.243, use: ZK_SERVER_HOST=http://127.0.0.1:8080");
        $this->line(" 3. Verify Windows Firewall on 192.168.9.243 allows TCP inbound on port 8080");

        return 1;
    }
}
