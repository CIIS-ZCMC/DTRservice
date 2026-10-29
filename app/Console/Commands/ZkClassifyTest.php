<?php

namespace App\Console\Commands;

use App\Models\Biometrics;
use App\Services\UmisPunchClassifierService;
use App\Services\ZkPunchForwarderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ZkClassifyTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zk:classify-test
                            {pin : Canonical biometric ID (UMIS PIN)}
                            {time? : Timestamp (YYYY-MM-DD HH:MM:SS, defaults to now)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate and preview how UMIS classifies an attendance punch into strictly 0 (In) or 1 (Out) for HRBLIZ';

    public function handle(
        UmisPunchClassifierService $classifier,
        ZkPunchForwarderService $forwarder
    ): int {
        $pin = $this->argument('pin');
        $timeStr = $this->argument('time') ?: now()->format('Y-m-d H:i:s');

        try {
            $parsedTime = Carbon::parse($timeStr)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            $this->error("Invalid timestamp format: {$timeStr}");
            return 1;
        }

        $bio = Biometrics::where('biometric_id', $pin)->first();
        $employeeName = $bio?->name ?? 'Unknown Employee';
        $hrbPin = $bio?->hrbliz_biometric_id;
        $forwardPin = $forwarder->resolveForwardPin($pin);

        // Classify
        $status = $classifier->classifyStatus($pin, $parsedTime);
        $statusText = $status === 0 ? '0 (Check-In)' : '1 (Check-Out)';

        $this->info("=== UMIS Punch Classification Test ===");
        $this->table(
            ['Field', 'Value'],
            [
                ['Canonical Biometric ID (UMIS)', $pin],
                ['Employee Name', $employeeName],
                ['HRBLIZ Biometric ID', $hrbPin ? "<fg=green>{$hrbPin}</>" : '<fg=yellow>None (Falls back to ' . $pin . ')</>'],
                ['Target Forwarded PIN', "<fg=cyan;options=bold>{$forwardPin}</>"],
                ['Timestamp', $parsedTime],
                ['Resolved Status for HRBLIZ', $status === 0 ? "<fg=green;options=bold>{$statusText}</>" : "<fg=cyan;options=bold>{$statusText}</>"],
                ['Target Endpoint', $forwarder->getHost() . '/api/punches'],
            ]
        );

        $this->newLine();
        $this->comment("Simulated Payload sent to Port 8080:");
        $this->line(json_encode([
            'pin' => $forwardPin,
            'time' => $parsedTime,
            'status' => $status,
            'device_sn' => 'SIMULATION_TEST',
        ], JSON_PRETTY_PRINT));

        return 0;
    }
}
