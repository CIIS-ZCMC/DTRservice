<?php

namespace App\Contracts;

interface DtrReportRepositoryInterface
{
    /**
     * Get DTR data for an employee within a date range
     */
    public function getEmployeeDtrData(int $biometricId, string $dateFrom, string $dateTo): array;

    /**
     * Generate report data with calculated totals
     */
    public function generateReport(int $biometricId, string $dateFrom, string $dateTo): array;
}
