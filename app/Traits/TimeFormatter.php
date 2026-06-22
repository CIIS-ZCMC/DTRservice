<?php

namespace App\Traits;

trait TimeFormatter
{
    /**
     * Convert minutes to hours and minutes format
     *
     * @param int $minutes
     * @return string
     */
    public function minutesToHoursMinutes(int $minutes): string
    {
        if ($minutes === 0) {
            return "";
        }

        $hours = (int) floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        $hourText = $hours === 1 ? 'hour' : 'hours';
        $minuteText = $remainingMinutes === 1 ? 'minute' : 'minutes';

        if ($hours === 0) {
            return "{$remainingMinutes} {$minuteText}";
        }

        return "{$hours} {$hourText} and {$remainingMinutes} {$minuteText}";
    }
}
