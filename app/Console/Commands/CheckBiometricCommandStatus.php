<?php

namespace App\Console\Commands;

use App\Models\Devices;
use App\Services\DeviceCommandService;
use Illuminate\Console\Command;

class CheckBiometricCommandStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:command-status
                            {--device= : Filter by device serial number}
                            {--pin= : Filter by biometric ID / PIN}
                            {--status= : Filter by status (PENDING, SENT, SUCCESS, FAILED)}
                            {--limit=50 : Maximum records to show}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'View real-time execution & sync status of queued device biometric commands';

    public function __construct(
        protected DeviceCommandService $commandService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $deviceFilter = $this->option('device');
        $pinFilter = $this->option('pin');
        $statusFilter = $this->option('status') ? strtoupper($this->option('status')) : null;
        $limit = (int)$this->option('limit');

        $this->commandService->pruneCompletedFiles();

        $allCommands = $this->commandService->getAllCommands($deviceFilter);

        if (empty($allCommands)) {
            $this->info('No device commands found in storage.');
            return 0;
        }

        // Calculate summary counts
        $total = count($allCommands);
        $pending = 0;
        $sent = 0;
        $success = 0;
        $failed = 0;

        foreach ($allCommands as $cmd) {
            $st = $cmd['status'] ?? 'PENDING';
            if ($st === 'PENDING') $pending++;
            elseif ($st === 'SENT') $sent++;
            elseif ($st === 'SUCCESS') $success++;
            elseif ($st === 'FAILED') $failed++;
        }

        $this->info('========================================================================================');
        $this->info('  DEVICE COMMAND QUEUE & SYNC STATUS');
        $this->info('========================================================================================');
        $this->line("Total Commands: <options=bold>{$total}</> | Pending: <fg=yellow>{$pending}</> | Sent: <fg=cyan>{$sent}</> | Synced/Success: <fg=green>{$success}</> | Failed: <fg=red>{$failed}</>");

        $files = $this->commandService->getAllCommandFiles();
        $fileInfoList = [];
        foreach ($files as $f) {
            if (file_exists($f)) {
                $fileInfoList[] = basename($f) . ' (' . round(filesize($f) / 1024 / 1024, 2) . ' MB)';
            }
        }
        $fileSummary = !empty($fileInfoList) ? implode(', ', $fileInfoList) : 'device_commands.json';
        $this->line("Queue Files: <fg=gray>{$fileSummary}</>");
        $this->newLine();

        // Device lookup map
        $deviceMap = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('devices')) {
            $deviceMap = Devices::pluck('device_name', 'serial_number')->toArray();
        }

        // Filter commands for display
        $filtered = array_filter($allCommands, function ($cmd) use ($pinFilter, $statusFilter) {
            if ($statusFilter && ($cmd['status'] ?? '') !== $statusFilter) {
                return false;
            }
            if ($pinFilter && !str_contains($cmd['command'] ?? '', "PIN={$pinFilter}")) {
                return false;
            }
            return true;
        });

        // Sort latest first
        usort($filtered, fn($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));
        $slice = array_slice($filtered, 0, $limit);

        if (empty($slice)) {
            $this->warn('No commands matched your filters.');
            return 0;
        }

        $tableRows = [];
        foreach ($slice as $cmd) {
            $rawCmd = $cmd['command'] ?? '';
            $pin = '-';
            if (preg_match('/PIN=(\d+)/i', $rawCmd, $m)) {
                $pin = $m[1];
            }

            $cmdType = 'OTHER';
            if (str_contains($rawCmd, 'DATA USER')) {
                $cmdType = 'USER_PROFILE';
            } elseif (str_contains($rawCmd, 'DATA UPDATE fingertmp')) {
                preg_match('/FID=(\d+)/i', $rawCmd, $fm);
                $cmdType = 'FINGER_UPDATE (FID ' . ($fm[1] ?? '?') . ')';
            } elseif (str_contains($rawCmd, 'DATA DELETE FINGERTMP')) {
                preg_match('/FID=(\d+)/i', $rawCmd, $fm);
                $cmdType = 'FINGER_DELETE (FID ' . ($fm[1] ?? '?') . ')';
            } elseif (str_contains($rawCmd, 'DATA UPDATE biodata')) {
                $cmdType = 'FACE_NIR_UPDATE';
            } elseif (str_contains($rawCmd, 'DATA UPDATE biophoto')) {
                $cmdType = 'FACE_PHOTO_UPDATE';
            } elseif (str_contains($rawCmd, 'DATA DELETE USER')) {
                $cmdType = 'USER_DELETE';
            }

            $st = $cmd['status'] ?? 'PENDING';
            $statusFormatted = match ($st) {
                'SUCCESS' => "<fg=green>SUCCESS (Ret=0)</>",
                'FAILED' => "<fg=red>FAILED (Ret=" . ($cmd['return_code'] ?? -1) . ")</>",
                'SENT' => "<fg=cyan>SENT (Awaiting ACK)</>",
                default => "<fg=yellow>PENDING</>",
            };

            $sn = $cmd['device_sn'] ?? 'Unknown';
            $devName = $deviceMap[$sn] ?? $sn;

            $tableRows[] = [
                'id' => $cmd['id'] ?? '-',
                'device' => substr($devName, 0, 20),
                'sn' => $sn,
                'pin' => $pin,
                'command_type' => $cmdType,
                'status' => $statusFormatted,
                'updated_at' => $cmd['updated_at'] ?? $cmd['created_at'] ?? '-',
            ];
        }

        $this->table(
            ['Cmd ID', 'Device Name', 'Serial Number', 'PIN', 'Command Type', 'Sync Status', 'Last Updated'],
            $tableRows
        );

        $today = now()->format('Y-m-d');
        $this->line("Live ACK Log: storage/logs/sync_ack_{$today}.txt");
        $this->line("Push Queue Log: storage/logs/sync_pushed_{$today}.txt");

        return 0;
    }
}
