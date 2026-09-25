<?php

namespace App\Console\Commands;

use App\Services\DeviceCommandService;
use Illuminate\Console\Command;

class ResolveSentBiometricCommands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometrics:resolve-sent
                            {--days=3 : Number of days threshold (default: 3)}
                            {--mode=within : Resolution mode: "within" (sent within last X days), "older_than" (older than X days), or "all"}
                            {--device= : Filter by device serial number}
                            {--grace-minutes=0 : Minimum age in minutes to avoid freshly dispatched commands}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resolve stuck or stacked-up SENT device commands to SUCCESS (and auto-delete completed queue files)';

    public function handle(DeviceCommandService $commandService): int
    {
        $days = (int)$this->option('days');
        $mode = strtolower((string)$this->option('mode'));
        $deviceSn = $this->option('device');
        $graceMinutes = (int)$this->option('grace-minutes');

        $this->info("Scanning queue files for stuck SENT commands...");
        $this->line("Mode: <fg=cyan>{$mode}</> | Days: <fg=yellow>{$days}</>" . ($deviceSn ? " | Device: <fg=gray>{$deviceSn}</>" : ""));

        $resolved = $commandService->resolveSentCommandsAsSuccess($days, $mode, $deviceSn, $graceMinutes);

        if ($resolved === 0) {
            $this->info("No stuck SENT commands matched the criteria.");
            return 0;
        }

        $this->info("Successfully changed <options=bold>{$resolved}</> stuck SENT command(s) to <fg=green>SUCCESS</>!");
        $this->line("<fg=green>Completed queue files have been automatically deleted from storage.</>");

        return 0;
    }
}
