<?php

namespace App\Services;

use App\Contracts\DtrReportRepositoryInterface;
use Illuminate\Support\Facades\Log;

class DtrReportService
{
    public function __construct(
        protected DtrReportRepositoryInterface $dtrReportRepository
    ) {}

    /**
     * Generate DTR report for an employee
     */
    public function generateReport(int $biometricId, string $dateFrom, string $dateTo): array
    {
        // Validate date format
        if (!$this->validateDate($dateFrom) || !$this->validateDate($dateTo)) {
            throw new \InvalidArgumentException('Invalid date format. Use Y-m-d format.');
        }

        // Validate date range
        if (strtotime($dateFrom) > strtotime($dateTo)) {
            throw new \InvalidArgumentException('Date from must be before or equal to date to.');
        }

        return $this->dtrReportRepository->generateReport($biometricId, $dateFrom, $dateTo);
    }

    /**
     * Validate date format (Y-m-d)
     */
    private function validateDate(string $date): bool
    {
        return (bool) strtotime($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }
}
