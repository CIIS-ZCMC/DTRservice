<?php

namespace App\Contracts;

use App\Models\DeviceLogs;

interface LogsRepositoryInterface
{
    /**
     * Create a new attendance log
     */
    public function createLog(array $data) :DeviceLogs;

    /**
     * Check if a log already exists
     */
    public function logExists(int $biometricId, string $dateTime): bool;

    /**
     * Get logs by date range
     */
    public function getLogsByDateRange(?string $dateFrom = null, ?string $dateTo = null): array;
    
    public function writeToFile(array $data): void;

    public function writeStructuredLog(array $data, ?string $rawLine = null): void;
    }
