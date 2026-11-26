<?php

namespace App\Services;

use App\Models\DeviceLogs;
use App\Models\DeviceLogsHrbliz;
use App\Models\EmployeeProfile;
use App\Models\ExternalEmployee;
use App\Models\ExternalSchedule;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UmisPunchClassifierService
{
    /**
     * Matching window around each scheduled timestamp (3 hours 30 minutes).
     */
    protected const WINDOW_MINUTES = 210;

    /**
     * Cross-midnight checkout window when the current day has no explicit schedule (6 hours).
     */
    protected const CROSS_MIDNIGHT_NO_SCHED_WINDOW_MINUTES = 360;

    /**
     * Rapid duplicate/bounce tap threshold in seconds.
     */
    protected const BOUNCE_TAP_SECONDS = 60;

    protected ?TimeRecordService $timeRecordService;

    /** @var array<string, bool> */
    protected array $tableExistsCache = [];

    /** @var array<int, int[]> */
    protected array $profileIdsCache = [];

    /** @var array<int, ?int> */
    protected array $externalEmployeeIdCache = [];

    /** @var array<string, array{times: array<int, string>, source: ?string}> */
    protected array $explicitScheduleCache = [];

    /** @var array<string, array<int, string>> */
    protected array $preloadedDayPunches = [];

    public function __construct(?TimeRecordService $timeRecordService = null)
    {
        $this->timeRecordService = $timeRecordService ?? app(TimeRecordService::class);
    }

    /**
     * Clear in-memory caches (useful between test cases or long-running CLI batches).
     */
    public function clearCache(): void
    {
        $this->tableExistsCache = [];
        $this->profileIdsCache = [];
        $this->externalEmployeeIdCache = [];
        $this->explicitScheduleCache = [];
        $this->preloadedDayPunches = [];
    }

    /**
     * Preload schedules (target dates + previous day for cross-midnight shifts) and
     * existing day punches for a batch of canonical (pin, date) pairs to eliminate N+1 queries.
     *
     * @param array<int, array{pin: int|string, date: string}> $pinDatePairs
     */
    public function preloadBatchContext(array $pinDatePairs): void
    {
        if (empty($pinDatePairs)) {
            return;
        }

        $allPins = [];
        $internalPins = [];
        $targetDates = [];
        $allScheduleDates = [];

        foreach ($pinDatePairs as $pair) {
            $pin = (int)($pair['pin'] ?? 0);
            $date = trim((string)($pair['date'] ?? ''));
            if ($pin <= 0 || $date === '') {
                continue;
            }

            $allPins[$pin] = true;
            if ($pin < 8000) {
                $internalPins[$pin] = true;
            }
            $targetDates[$date] = true;
            $allScheduleDates[$date] = true;

            try {
                $prevDate = Carbon::parse($date)->subDay()->format('Y-m-d');
                $allScheduleDates[$prevDate] = true;
            } catch (\Throwable) {
            }
        }

        $allPinList = array_keys($allPins);
        $internalPinList = array_keys($internalPins);
        $targetDateList = array_keys($targetDates);
        $allScheduleDateList = array_keys($allScheduleDates);

        if (empty($allPinList) || empty($targetDateList)) {
            return;
        }

        // 1. Preload internal EmployeeProfile IDs and UMIS schedules (including previous day for cross-midnight)
        if (!empty($internalPinList)
            && $this->hasTable('employee_profiles')
            && $this->hasTable('schedules')
            && $this->hasTable('employee_profile_schedule')
            && $this->hasTable('time_shifts')
        ) {
            try {
                $profiles = EmployeeProfile::whereIn('biometric_id', $internalPinList)
                    ->whereNull('deleted_at')
                    ->get(['id', 'biometric_id']);

                foreach ($internalPinList as $pin) {
                    $this->profileIdsCache[$pin] = [];
                }

                $profileToPin = [];
                $allProfileIds = [];
                foreach ($profiles as $profile) {
                    $bioId = (int)$profile->biometric_id;
                    $pid = (int)$profile->id;
                    $this->profileIdsCache[$bioId][] = $pid;
                    $profileToPin[$pid] = $bioId;
                    $allProfileIds[] = $pid;
                }

                // Initialize schedule cache entries as null (no schedule) for preloaded pins/dates
                foreach ($internalPinList as $pin) {
                    foreach ($allScheduleDateList as $d) {
                        $key = "{$pin}_{$d}";
                        if (!array_key_exists($key, $this->explicitScheduleCache)) {
                            $this->explicitScheduleCache[$key] = ['times' => [], 'source' => null];
                        }
                    }
                }

                if (!empty($allProfileIds)) {
                    $rows = DB::table('employee_profile_schedule as eps')
                        ->join('schedules as s', 's.id', '=', 'eps.schedule_id')
                        ->join('time_shifts as ts', 'ts.id', '=', 's.time_shift_id')
                        ->whereIn('eps.employee_profile_id', $allProfileIds)
                        ->whereIn('s.date', $allScheduleDateList)
                        ->whereNull('s.deleted_at')
                        ->orderBy('s.id', 'asc')
                        ->select([
                            'eps.employee_profile_id',
                            's.date',
                            'ts.first_in',
                            'ts.first_out',
                            'ts.second_in',
                            'ts.second_out',
                        ])
                        ->get();

                    foreach ($rows as $row) {
                        $bioId = $profileToPin[(int)$row->employee_profile_id] ?? null;
                        if ($bioId === null) {
                            continue;
                        }
                        $times = $this->extractNormalizedTimes(
                            $row->first_in,
                            $row->first_out,
                            $row->second_in,
                            $row->second_out
                        );
                        if (!empty($times)) {
                            // Ordered by s.id asc so the latest schedule ID wins
                            $this->explicitScheduleCache["{$bioId}_{$row->date}"] = [
                                'times' => $times,
                                'source' => 'umis_schedule',
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('device_logs')->debug('UmisPunchClassifierService::preloadBatchContext schedule preload error: ' . $e->getMessage());
            }
        }

        // 2. Preload existing day punches from device_logs and device_logs_hrbliz
        foreach ($pinDatePairs as $pair) {
            $pin = (int)($pair['pin'] ?? 0);
            $date = trim((string)($pair['date'] ?? ''));
            if ($pin > 0 && $date !== '') {
                $this->preloadedDayPunches["{$pin}_{$date}"] = [];
            }
        }

        try {
            $pinStrings = array_map('strval', $allPinList);

            if ($this->hasTable('device_logs')) {
                $stdRows = DeviceLogs::whereIn('biometric_id', $pinStrings)
                    ->whereIn('dtr_date', $targetDateList)
                    ->orderBy('date_time', 'asc')
                    ->get(['biometric_id', 'dtr_date', 'date_time']);

                foreach ($stdRows as $row) {
                    $key = ((int)$row->biometric_id) . '_' . $row->dtr_date;
                    if (isset($this->preloadedDayPunches[$key])) {
                        $this->preloadedDayPunches[$key][] = (string)$row->date_time;
                    }
                }
            }

            if ($this->hasTable('device_logs_hrbliz')) {
                $hrbRows = DeviceLogsHrbliz::whereIn('biometric_id', $pinStrings)
                    ->whereIn('dtr_date', $targetDateList)
                    ->orderBy('date_time', 'asc')
                    ->get(['biometric_id', 'dtr_date', 'date_time']);

                foreach ($hrbRows as $row) {
                    $key = ((int)$row->biometric_id) . '_' . $row->dtr_date;
                    if (isset($this->preloadedDayPunches[$key])) {
                        $this->preloadedDayPunches[$key][] = (string)$row->date_time;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('UmisPunchClassifierService::preloadBatchContext punches preload error: ' . $e->getMessage());
        }
    }

    /**
     * Classify an attendance punch timestamp into strictly 0 (Check-In) or 1 (Check-Out)
     * using UMIS schedule windows and chronological rules.
     *
     * @param int|string $biometricId Canonical biometric ID (UMIS PIN)
     * @param string $dateTime Full timestamp (YYYY-MM-DD HH:MM:SS)
     * @param array<int, string> $knownDayPunches Optional already-known timestamps for today
     * @param bool $allowDefaultScheduleFallback Whether to use default 08:00-12:00 / 13:00-17:00 schedule when no UMIS schedule exists (exclusive to ZK_FORWARD_DEVICE_IDS)
     * @return int 0 for Check-In, 1 for Check-Out
     */
    public function classifyStatus(
        int|string $biometricId,
        string $dateTime,
        array $knownDayPunches = [],
        bool $allowDefaultScheduleFallback = true
    ): int {
        return $this->classifyWithDetails(
            $biometricId,
            $dateTime,
            $knownDayPunches,
            $allowDefaultScheduleFallback
        )['status'];
    }

    /**
     * Classify a punch and return full diagnostic details (status, schedule source, matched slot, schedule times).
     *
     * @param int|string $biometricId Canonical biometric ID (UMIS PIN)
     * @param string $dateTime Full timestamp (YYYY-MM-DD HH:MM:SS)
     * @param array<int, string> $knownDayPunches Optional already-known timestamps for today
     * @param bool $allowDefaultScheduleFallback Whether to fall back to default 08:00-12:00 / 13:00-17:00 when no UMIS schedule is found
     * @return array{status: int, source: string, slot: ?string, schedule_times: array<int, string>}
     */
    public function classifyWithDetails(
        int|string $biometricId,
        string $dateTime,
        array $knownDayPunches = [],
        bool $allowDefaultScheduleFallback = true
    ): array {
        $bioId = (int)$biometricId;

        try {
            $punchCarbon = Carbon::parse($dateTime);
        } catch (\Throwable) {
            $punchCarbon = now();
        }

        $date = $punchCarbon->format('Y-m-d');
        $currentDtStr = $punchCarbon->format('Y-m-d H:i:s');

        // 1. Check today's explicit UMIS / External schedule first
        $todayExplicit = $this->getExplicitSchedule($bioId, $date);
        $hasExplicitToday = !empty($todayExplicit['times']);

        // 2. Check previous day's cross-midnight shift checkout (e.g., 22:00 -> 06:00 next morning)
        $prevDate = $punchCarbon->copy()->subDay()->format('Y-m-d');
        $prevExplicit = $this->getExplicitSchedule($bioId, $prevDate);
        $prevCrossMidnightOut = $this->getCrossMidnightCheckoutTime($prevExplicit['times'], $date);

        if ($prevCrossMidnightOut !== null && $this->isPunchCrossMidnightCheckout(
            $punchCarbon,
            $prevCrossMidnightOut,
            $hasExplicitToday ? $todayExplicit['times'] : null,
            $date
        )) {
            return [
                'status' => 1,
                'source' => 'prev_cross_midnight',
                'slot' => 'cross_midnight_out',
                'schedule_times' => $prevExplicit['times'],
            ];
        }

        // 3. Determine effective schedule for today:
        //    - User's assigned UMIS/External schedule on this date
        //    - If none found and fallback is allowed (exclusive to ZK_FORWARD_DEVICE_IDS=27): default 08:00-12:00 / 13:00-17:00
        if ($hasExplicitToday) {
            $scheduleTimes = $todayExplicit['times'];
            $scheduleSource = $todayExplicit['source'] ?? 'umis_schedule';
        } elseif ($allowDefaultScheduleFallback) {
            $scheduleTimes = $this->getDefaultScheduleTimes();
            $scheduleSource = 'default_8_12_1_5';
        } else {
            $scheduleTimes = [];
            $scheduleSource = 'chronological_fallback';
        }

        // 4. Gather all distinct punches for this employee on this date
        $dayPunches = $this->getDayPunchesForDate($bioId, $date, $currentDtStr, $knownDayPunches);

        // Exclude morning checkouts that belonged to yesterday's cross-midnight shift
        if ($prevCrossMidnightOut !== null) {
            $dayPunches = array_values(array_filter($dayPunches, function (string $dtStr) use ($prevCrossMidnightOut, $hasExplicitToday, $todayExplicit, $date) {
                try {
                    return !$this->isPunchCrossMidnightCheckout(
                        Carbon::parse($dtStr),
                        $prevCrossMidnightOut,
                        $hasExplicitToday ? $todayExplicit['times'] : null,
                        $date
                    );
                } catch (\Throwable) {
                    return true;
                }
            }));

            if (!in_array($currentDtStr, $dayPunches, true)) {
                $dayPunches[] = $currentDtStr;
                sort($dayPunches);
            }
        }

        // 5. Debounce rapid bounce taps (< 60s apart) so duplicate taps share the same classification
        //    and do not consume the next slot.
        [$debouncedPunches, $targetGroupIndex] = $this->debouncePunches($dayPunches, $currentDtStr);

        // 6. Match debounced punches against resolved schedule slots
        if (!empty($scheduleTimes) && !empty($debouncedPunches)) {
            $matched = $this->classifyViaScheduleSlots($debouncedPunches, $scheduleTimes, $date, $targetGroupIndex);
            if ($matched !== null) {
                return [
                    'status' => $matched['status'],
                    'source' => $scheduleSource,
                    'slot' => $matched['slot'],
                    'schedule_times' => $scheduleTimes,
                ];
            }
        }

        // 7. Overflow / fallback (e.g. 5th+ overtime punch or no-schedule non-27 caller):
        //    Strict debounced chronological alternation (1st=0/In, 2nd=1/Out, 3rd=0/In, 4th=1/Out, ...)
        return [
            'status' => ($targetGroupIndex % 2 === 0) ? 0 : 1,
            'source' => $scheduleSource === 'chronological_fallback' ? 'chronological_fallback' : $scheduleSource,
            'slot' => null,
            'schedule_times' => $scheduleTimes,
        ];
    }

    /**
     * Get the default 8-12 / 1-5 schedule times.
     *
     * @return array<int, string>
     */
    public function getDefaultScheduleTimes(): array
    {
        return [
            (string)config('app.default_first_in', '08:00:00'),
            (string)config('app.default_first_out', '12:00:00'),
            (string)config('app.default_last_in', '13:00:00'),
            (string)config('app.default_last_out', '17:00:00'),
        ];
    }

    /**
     * Debounce chronologically sorted punches within BOUNCE_TAP_SECONDS (60s).
     * Returns the list of group anchor timestamps and the group index containing $targetDtStr.
     *
     * @param array<int, string> $sortedPunches
     * @param string $targetDtStr
     * @return array{0: array<int, string>, 1: int}
     */
    protected function debouncePunches(array $sortedPunches, string $targetDtStr): array
    {
        $groups = [];
        $lastAnchorTs = null;
        $targetGroupIndex = 0;

        foreach ($sortedPunches as $dtStr) {
            $ts = strtotime($dtStr);
            if ($ts === false) {
                continue;
            }

            if ($lastAnchorTs === null || ($ts - $lastAnchorTs) >= self::BOUNCE_TAP_SECONDS) {
                $groups[] = $dtStr;
                $lastAnchorTs = $ts;
            }

            if ($dtStr === $targetDtStr) {
                $targetGroupIndex = count($groups) - 1;
            }
        }

        if (empty($groups)) {
            return [[$targetDtStr], 0];
        }

        return [$groups, $targetGroupIndex];
    }

    /**
     * Match debounced punches chronologically against schedule slots using closest-slot matching
     * from the current pointer within WINDOW_MINUTES (210m), with sequential empty-slot fallback.
     *
     * @param array<int, string> $debouncedPunches
     * @param array<int, string> $scheduleTimes Normalized sequential times (2-slot or 4-slot)
     * @param string $date YYYY-MM-DD
     * @param int $targetGroupIndex Index in $debouncedPunches to classify
     * @return array{status: int, slot: string}|null
     */
    protected function classifyViaScheduleSlots(
        array $debouncedPunches,
        array $scheduleTimes,
        string $date,
        int $targetGroupIndex
    ): ?array {
        $slotCount = count($scheduleTimes);
        if ($slotCount < 2) {
            return null;
        }

        $slotKeys = $slotCount === 2
            ? ['first_in', 'first_out']
            : ['first_in', 'first_out', 'second_in', 'second_out'];

        // Build continuous timeline for schedule anchored on $date
        $baseDate = Carbon::parse($date)->startOfDay();
        $scheduleCarbons = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $t = Carbon::parse($scheduleTimes[$i])->startOfMinute()->setDateFrom($baseDate);
            if ($i > 0) {
                while ($t->lt($scheduleCarbons[$i - 1])) {
                    $t->addDay();
                }
            }
            $scheduleCarbons[$i] = $t;
        }

        $filledSlots = array_fill(0, $slotCount, null);
        $pointer = 0;
        $punchCount = count($debouncedPunches);

        for ($pIdx = 0; $pIdx < $punchCount; $pIdx++) {
            $entryTime = Carbon::parse($debouncedPunches[$pIdx])->startOfMinute();

            if ($pointer >= $slotCount) {
                // All schedule slots already filled (5th+ punch / overtime)
                if ($pIdx === $targetGroupIndex) {
                    return null;
                }
                continue;
            }

            // Pick the CLOSEST slot from $pointer onward within WINDOW_MINUTES
            $bestSlot = null;
            $bestDiff = null;
            for ($i = $pointer; $i < $slotCount; $i++) {
                $diff = abs($entryTime->diffInMinutes($scheduleCarbons[$i]));
                if ($diff <= self::WINDOW_MINUTES) {
                    // Break exact midpoint ties in favor of Out (odd slot) when employee is already clocked in
                    if ($bestDiff === null || $diff < $bestDiff || ($diff === $bestDiff && $pointer > 0 && ($i % 2 === 1))) {
                        $bestDiff = $diff;
                        $bestSlot = $i;
                    }
                }
            }

            // Late-break disambiguation for 4-slot schedules:
            // If pointer is at first_out (1), bestSlot picked second_in (2), AND there is another punch
            // right after this one that is also closer to second_in (2) than second_out (3) (e.g. 13:02 out, 13:04 in),
            // assign the earlier punch to first_out (1) so the later punch takes second_in (2).
            if (
                $slotCount === 4
                && $pointer === 1
                && $bestSlot === 2
                && ($pIdx + 1) < $punchCount
            ) {
                $nextTime = Carbon::parse($debouncedPunches[$pIdx + 1])->startOfMinute();
                $nextDiffSecondIn = abs($nextTime->diffInMinutes($scheduleCarbons[2]));
                $nextDiffSecondOut = abs($nextTime->diffInMinutes($scheduleCarbons[3]));
                if ($nextDiffSecondIn <= self::WINDOW_MINUTES && $nextDiffSecondIn < $nextDiffSecondOut) {
                    $bestSlot = 1;
                }
            }

            // Fallback: if outside 210m of all remaining slots (e.g., very early out or late overtime out),
            // place in the next empty slot from $pointer onward
            if ($bestSlot === null) {
                for ($i = $pointer; $i < $slotCount; $i++) {
                    if ($filledSlots[$i] === null) {
                        $bestSlot = $i;
                        break;
                    }
                }
            }

            if ($bestSlot !== null) {
                while ($bestSlot < $slotCount && $filledSlots[$bestSlot] !== null) {
                    $bestSlot++;
                }

                if ($bestSlot < $slotCount) {
                    $filledSlots[$bestSlot] = $entryTime->toDateTimeString();
                    $pointer = $bestSlot + 1;

                    if ($pIdx === $targetGroupIndex) {
                        $slotName = $slotKeys[$bestSlot] ?? "slot_{$bestSlot}";
                        // Even slots (first_in=0, second_in=2) => 0 (Check-In)
                        // Odd slots (first_out=1, second_out=3) => 1 (Check-Out)
                        return [
                            'status' => ($bestSlot % 2 === 0) ? 0 : 1,
                            'slot' => $slotName,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * If the given schedule crosses midnight (last time <= first time, e.g. 22:00 -> 06:00),
     * return the expected checkout Carbon timestamp on $currentDate; otherwise null.
     *
     * @param array<int, string> $prevScheduleTimes
     * @param string $currentDate
     * @return Carbon|null
     */
    protected function getCrossMidnightCheckoutTime(array $prevScheduleTimes, string $currentDate): ?Carbon
    {
        if (count($prevScheduleTimes) < 2) {
            return null;
        }

        $firstTime = $prevScheduleTimes[0];
        $lastTime = $prevScheduleTimes[count($prevScheduleTimes) - 1];

        try {
            $firstCarbon = Carbon::parse($firstTime);
            $lastCarbon = Carbon::parse($lastTime);
            if ($lastCarbon->lessThanOrEqualTo($firstCarbon)) {
                return Carbon::parse("{$currentDate} {$lastTime}");
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Determine whether $punchCarbon is the morning check-out from yesterday's cross-midnight shift.
     *
     * @param Carbon $punchCarbon
     * @param Carbon $prevOutCarbon
     * @param array<int, string>|null $todayScheduleTimes
     * @param string $date
     * @return bool
     */
    protected function isPunchCrossMidnightCheckout(
        Carbon $punchCarbon,
        Carbon $prevOutCarbon,
        ?array $todayScheduleTimes,
        string $date
    ): bool {
        $diffToPrevOut = abs($punchCarbon->diffInMinutes($prevOutCarbon));

        // If today has an explicit non-cross-midnight schedule, don't steal a punch closer to today's start
        if (!empty($todayScheduleTimes)) {
            try {
                $todayStart = Carbon::parse("{$date} {$todayScheduleTimes[0]}");
                $diffToTodayStart = abs($punchCarbon->diffInMinutes($todayStart));

                // If today's shift starts after prevOut, punch must be strictly closer to prevOut than today's start
                if ($todayStart->greaterThan($prevOutCarbon) && $diffToTodayStart <= $diffToPrevOut) {
                    return false;
                }
            } catch (\Throwable) {
            }

            return $diffToPrevOut <= self::WINDOW_MINUTES;
        }

        // No schedule today (typical Day Off after a night shift): allow up to 6h window around checkout
        return $diffToPrevOut <= self::CROSS_MIDNIGHT_NO_SCHED_WINDOW_MINUTES;
    }

    /**
     * Retrieve all chronological punch timestamps for this employee on $date (from caller context + DB),
     * preserving full same-day context for accurate slot disambiguation (e.g. late lunch breaks in batches).
     *
     * @param int $bioId
     * @param string $date
     * @param string $currentDtStr
     * @param array<int, string> $knownDayPunches
     * @return array<int, string> Sorted datetime strings (Y-m-d H:i:s)
     */
    protected function getDayPunchesForDate(int $bioId, string $date, string $currentDtStr, array $knownDayPunches = []): array
    {
        $entries = [];

        // Include caller-supplied known punches for this date
        foreach ($knownDayPunches as $kp) {
            try {
                $kpCarbon = Carbon::parse($kp);
                if ($kpCarbon->format('Y-m-d') === $date) {
                    $entries[] = $kpCarbon->format('Y-m-d H:i:s');
                }
            } catch (\Throwable) {
            }
        }

        $cacheKey = "{$bioId}_{$date}";
        if (array_key_exists($cacheKey, $this->preloadedDayPunches)) {
            $entries = array_merge($entries, $this->preloadedDayPunches[$cacheKey]);
        } else {
            try {
                if ($this->hasTable('device_logs')) {
                    $logs = DeviceLogs::where('biometric_id', (string)$bioId)
                        ->where('dtr_date', $date)
                        ->orderBy('date_time', 'asc')
                        ->pluck('date_time')
                        ->all();
                    $entries = array_merge($entries, $logs);
                }

                if ($this->hasTable('device_logs_hrbliz')) {
                    $hrbLogs = DeviceLogsHrbliz::where('biometric_id', (string)$bioId)
                        ->where('dtr_date', $date)
                        ->orderBy('date_time', 'asc')
                        ->pluck('date_time')
                        ->all();
                    $entries = array_merge($entries, $hrbLogs);
                }
            } catch (\Throwable $e) {
                Log::channel('device_logs')->debug('getDayPunchesForDate error: ' . $e->getMessage());
            }
        }

        // Ensure current punch itself is included
        if (!in_array($currentDtStr, $entries, true)) {
            $entries[] = $currentDtStr;
        }

        $entries = array_values(array_unique($entries));
        sort($entries);

        return $entries;
    }

    /**
     * Resolve explicit schedule for an employee on a specific date ONLY (no stale other-date fallback).
     *
     * @param int $biometricId
     * @param string $date
     * @return array{times: array<int, string>, source: ?string}
     */
    protected function getExplicitSchedule(int $biometricId, string $date): array
    {
        $cacheKey = "{$biometricId}_{$date}";
        if (array_key_exists($cacheKey, $this->explicitScheduleCache)) {
            return $this->explicitScheduleCache[$cacheKey];
        }

        $result = ['times' => [], 'source' => null];

        // 1. Internal UMIS employee schedule (biometric_id < 8000)
        if ($biometricId < 8000) {
            $umisTimes = $this->fetchUmisScheduleTimes($biometricId, $date);
            if (!empty($umisTimes)) {
                $result = ['times' => $umisTimes, 'source' => 'umis_schedule'];
                $this->explicitScheduleCache[$cacheKey] = $result;
                return $result;
            }
        }

        // 2. External employee schedule (biometric_id >= 8000 or fallback when no internal schedule exists)
        $extTimes = $this->fetchExternalScheduleTimes($biometricId, $date);
        if (!empty($extTimes)) {
            $result = ['times' => $extTimes, 'source' => 'external_schedule'];
        }

        $this->explicitScheduleCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Fetch normalized UMIS TimeShift times for employee on exact $date.
     *
     * @return array<int, string>
     */
    protected function fetchUmisScheduleTimes(int $biometricId, string $date): array
    {
        if (!$this->hasTable('employee_profiles') || !$this->hasTable('schedules') || !$this->hasTable('time_shifts')) {
            return [];
        }

        try {
            if (!array_key_exists($biometricId, $this->profileIdsCache)) {
                $this->profileIdsCache[$biometricId] = EmployeeProfile::where('biometric_id', $biometricId)
                    ->whereNull('deleted_at')
                    ->pluck('id')
                    ->map(fn($id) => (int)$id)
                    ->all();
            }

            $profileIds = $this->profileIdsCache[$biometricId];
            if (empty($profileIds)) {
                return [];
            }

            $schedule = Schedule::where('date', $date)
                ->whereNull('deleted_at')
                ->whereHas('employeeSchedules', function ($query) use ($profileIds) {
                    $query->whereIn('employee_profile_id', $profileIds);
                })
                ->with('timeShift')
                ->orderBy('id', 'desc')
                ->first();

            $timeShift = $schedule?->timeShift;
            if (!$timeShift) {
                return [];
            }

            return $this->extractNormalizedTimes(
                $timeShift->first_in,
                $timeShift->first_out,
                $timeShift->second_in,
                $timeShift->second_out
            );
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('fetchUmisScheduleTimes error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch normalized ExternalSchedule times for external employee on exact $date.
     *
     * @return array<int, string>
     */
    protected function fetchExternalScheduleTimes(int $biometricId, string $date): array
    {
        if (!$this->hasTable('external_employees')) {
            return [];
        }

        try {
            if (!array_key_exists($biometricId, $this->externalEmployeeIdCache)) {
                $extEmp = ExternalEmployee::where('biometric_id', $biometricId)->first(['id']);
                $this->externalEmployeeIdCache[$biometricId] = $extEmp ? (int)$extEmp->id : null;
            }

            $extEmpId = $this->externalEmployeeIdCache[$biometricId];
            if ($extEmpId === null) {
                return [];
            }

            $extSched = ExternalSchedule::where('external_employee_id', $extEmpId)
                ->where('dtr_date', $date)
                ->first();

            if (!$extSched) {
                return [];
            }

            return $this->extractNormalizedTimes(
                $extSched->first_in,
                $extSched->first_out,
                $extSched->second_in,
                $extSched->second_out
            );
        } catch (\Throwable $e) {
            // Gracefully ignore when external_db connection is unavailable (e.g. local/test env)
            Log::channel('device_logs')->debug('fetchExternalScheduleTimes error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Normalize shift columns into a 0-indexed array of non-empty HH:MM:SS time strings.
     * Handles both 4-slot schedules (first_in, first_out, second_in, second_out)
     * and 2-slot schedules (first_in+first_out OR sparse first_in+second_out).
     *
     * @return array<int, string>
     */
    protected function extractNormalizedTimes(?string $firstIn, ?string $firstOut, ?string $secondIn, ?string $secondOut): array
    {
        $raw = [$firstIn, $firstOut, $secondIn, $secondOut];
        $filtered = [];

        foreach ($raw as $val) {
            if ($val !== null && trim($val) !== '') {
                $filtered[] = trim($val);
            }
        }

        return $filtered;
    }

    /**
     * Cached table existence check to avoid repeated information_schema queries.
     */
    protected function hasTable(string $table): bool
    {
        if (!array_key_exists($table, $this->tableExistsCache)) {
            try {
                $this->tableExistsCache[$table] = Schema::hasTable($table);
            } catch (\Throwable) {
                $this->tableExistsCache[$table] = false;
            }
        }

        return $this->tableExistsCache[$table];
    }
}
