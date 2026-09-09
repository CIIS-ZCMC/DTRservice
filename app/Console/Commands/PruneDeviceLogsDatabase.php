<?php

namespace App\Console\Commands;

use App\Contracts\LogsRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneDeviceLogsDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'device-logs:prune
                            {--years=1 : Retention period in years (default: 1)}
                            {--days= : Retention period in days (overrides --years)}
                            {--before= : Specific cutoff date (YYYY-MM-DD)}
                            {--chunk=2000 : Chunk size per deletion transaction}
                            {--archive : Export records to compressed JSON archive in storage/app/archive before deleting}
                            {--dry-run : Simulate pruning and display record counts without deleting}
                            {--force : Bypass confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune historical device logs from MySQL database older than specified retention period (default: 1 year)';

    public function __construct(protected LogsRepositoryInterface $logsRepository)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        @set_time_limit(600);

        $years = (int)($this->option('years') ?: 1);
        $days = $this->option('days') !== null ? (int)$this->option('days') : null;
        $before = $this->option('before');
        $chunkSize = (int)($this->option('chunk') ?: 2000);
        $archive = (bool)$this->option('archive');
        $dryRun = (bool)$this->option('dry-run');
        $force = (bool)$this->option('force');

        // Determine cutoff date
        if (!empty($before)) {
            if (!strtotime($before)) {
                $this->error("Invalid date format for --before: {$before}. Must be YYYY-MM-DD.");
                return 1;
            }
            $cutoffDate = Carbon::parse($before)->format('Y-m-d');
        } elseif ($days !== null) {
            $cutoffDate = now()->subDays($days)->format('Y-m-d');
        } else {
            $cutoffDate = now()->subYears($years)->format('Y-m-d');
        }

        // Strict Safety Guardrail: Can ONLY clear logs 1 year before today or older
        $maxAllowedCutoff = now()->subYear()->format('Y-m-d');
        if ($cutoffDate > $maxAllowedCutoff) {
            $this->error("Safety Violation: Database logs can ONLY be cleared if they are 1 year before today or older.");
            $this->error("Specified cutoff ({$cutoffDate}) is newer than 1 year before today ({$maxAllowedCutoff}). Operation aborted.");
            return 1;
        }

        if ($dryRun) {
            $this->warn("*** DRY-RUN MODE: Simulating database pruning. No records will be deleted. ***");
        }

        $this->info("Cutoff date: {$cutoffDate} (Records with dtr_date <= {$cutoffDate} will be pruned)");

        if (!$force && !$dryRun) {
            $archiveMsg = $archive ? "Records will be backed up to storage/app/archive first." : "NO archive requested.";
            $confirmed = $this->confirm("Are you sure you want to delete all device logs older than {$cutoffDate}? ({$archiveMsg})", false);
            if (!$confirmed) {
                $this->info("Pruning cancelled by user.");
                return 0;
            }
        }

        $this->info("Scanning database for eligible logs...");
        $result = $this->logsRepository->pruneLogs($cutoffDate, $chunkSize, $dryRun, $archive);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Cutoff Date', $result['cutoff_date']],
                ['Eligible Records Found', number_format($result['total_eligible'])],
                ['Records Deleted', number_format($result['deleted_count'])],
                ['Records Archived', number_format($result['archived_count'])],
                ['Archive File', $result['archive_file'] ?? 'N/A'],
                ['Execution Time', $result['duration_seconds'] . 's'],
                ['Mode', $result['dry_run'] ? 'DRY RUN (Simulated)' : 'EXECUTED (Permanent)'],
            ]
        );

        if ($dryRun) {
            $this->info("Dry-run complete. Run without --dry-run to permanently prune records.");
        } else {
            $this->info("Pruning successfully completed.");
            if ($result['archive_file']) {
                $this->info("Archived records saved to: storage/app/archive/{$result['archive_file']}");
            }
        }

        return 0;
    }
}
