<?php

namespace App\Contracts;

interface ScheduleRepositoryInterface
{
    /**
     * Get schedule by employee ID
     */
    public function getScheduleByDate(int $biometricId, string $date): ?array;

    
}
