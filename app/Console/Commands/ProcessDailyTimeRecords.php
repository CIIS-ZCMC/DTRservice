<?php

namespace App\Console\Commands;

use App\Contracts\TimeRecordRepositoryInterface;
use App\Services\TimeRecordService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessDailyTimeRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dtr:process {biometric_id? : The biometric ID of the employee} {--all : Process all employees with device logs} {--date= : The date to process (Y-m-d format)} {--from= : Start date for range processing} {--to= : End date for range processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Align device logs to Daily Time Record table';

    /**
     * Execute the console command.
     */

    public function __construct(private TimeRecordRepositoryInterface $timeRecordRepository, private TimeRecordService $timeRecordService) {
        parent::__construct();
    }

    public function handle()
    {
        $biometricId = $this->argument('biometric_id');
        $all = $this->option('all');
        $date = $this->option('date');
        $dateFrom = $this->option('from');
        $dateTo = $this->option('to');

        try {
            if ($all) {
             
                return $this->processAllEmployees($this->timeRecordRepository);
            } elseif ($dateFrom && $dateTo) {
               // return $this->processDateRange($this->timeRecordService, $biometricId, $dateFrom, $dateTo);
            } elseif ($date) {
                //return $this->processSingleDate($this->timeRecordService, $biometricId, $date);
            } else {
                $this->error('Please provide either --all, --date, or --from/--to options');
                return 1;
            }
        } catch (\Exception $e) {
            Log::error('Error processing daily time records: ' . $e->getMessage());
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    // private function processSingleDate(TimeRecordService $timeRecordService, ?int $biometricId, string $date): int
    // {
    //     // if (!$biometricId) {
    //     //     $this->error('Biometric ID is required for single date processing');
    //     //     return 1;
    //     // }

    //     // $this->info("Processing DTR for biometric ID: {$biometricId}, date: {$date}");

    //     // $timeRecordService->consolidateDailyTimeRecord($biometricId, $date);

    //     // $this->info("Successfully processed DTR for {$date}");
    //     // return 0;
    // }

    // private function processDateRange(TimeRecordService $timeRecordService, ?int $biometricId, string $dateFrom, string $dateTo): int
    // {
    //     // $this->info("Processing DTR from {$dateFrom} to {$dateTo}");

    //     // if ($biometricId) {
    //     //     $this->info("For biometric ID: {$biometricId}");
    //     //     $records = $timeRecordService->getEmployeeTimeRecordsRange($biometricId, $dateFrom, $dateTo);
    //     //     $this->info("Found " . count($records) . " records");

    //     //     $this->newLine();
    //     //     $this->withProgressBar($records, function ($record) use ($timeRecordService, $biometricId) {
    //     //         $timeRecordService->consolidateDailyTimeRecord($biometricId, $record['date']);
    //     //     });
    //     //     $this->newLine();
    //     // } else {
    //     //     $this->info("Processing all employees");
    //     //     return $this->processAllEmployees($timeRecordService, $dateFrom, $dateTo);
    //     // }

    //     // $this->info("Successfully processed DTR range from {$dateFrom} to {$dateTo}");
    //     // return 0;
    // }

    private function processAllEmployees(TimeRecordRepositoryInterface $timeRecordRepository): int
    {
        $dateFrom = now()->startOfMonth()->format('Y-m-d');
        $dateTo = now()->endOfMonth()->format('Y-m-d');
        $this->info("Processing all employees with device logs from {$dateFrom} to {$dateTo}");

        $biometricIds = $timeRecordRepository->getEmployeesWithDeviceLogs($dateFrom, $dateTo);
        $this->info("Found " . count($biometricIds) . " employees with device logs");

        if (empty($biometricIds)) {
            $this->warn("No employees found with device logs in the specified date range");
            return 0;
        }

        $days_In_Month = now()->daysInMonth;

        $this->newLine();
        $this->withProgressBar($biometricIds, function ($biometricId) use ($dateFrom, $days_In_Month) {
            $this->info(" Processing employee {$biometricId}");

            for ($i = 1; $i <= $days_In_Month; $i++) {
                try {
                    $currentDate = Carbon::parse($dateFrom)->day($i)->format('Y-m-d');
                    $this->timeRecordService->consolidateDailyTimeRecord((int)$biometricId, $currentDate);
                } catch (\Exception $e) {
                    $currentDate = Carbon::parse($dateFrom)->day($i)->format('Y-m-d');
                    Log::error("Error processing employee {$biometricId} for date {$currentDate}: " . $e->getMessage());
                }
            }

        });
        $this->newLine();

        $this->info("Successfully processed all employees from {$dateFrom} to {$dateTo}");
        return 0;
    }
}
