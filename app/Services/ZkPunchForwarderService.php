<?php

namespace App\Services;

use App\Models\Biometrics;
use App\Models\Devices;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ZkPunchForwarderService
{
    protected bool $enabled;
    protected string $host;
    protected int $timeout;
    protected ?string $apiKey;
    protected string $defaultDeviceSn;
    protected array $allowedDeviceIds;
    protected bool $useHrblizId;
    protected bool $strictHrblizStatus;
    protected int $batchSize;
    protected ?UmisPunchClassifierService $classifier;

    /** @var array<string, bool> */
    protected array $tableExistsCache = [];

    /** @var array<string, ?Devices> */
    protected array $deviceLookupCache = [];

    /** @var array<int, string> */
    protected array $pinMapCache = [];

    public function __construct(?UmisPunchClassifierService $classifier = null)
    {
        $this->enabled = (bool)config('zkteco.forward_enabled', true);
        $this->host = rtrim((string)config('zkteco.host', 'http://127.0.0.1:8080'), '/');
        $this->timeout = max(1, (int)config('zkteco.timeout', 3));
        $this->apiKey = config('zkteco.api_key');
        $this->defaultDeviceSn = (string)config('zkteco.device_sn', 'RKQ4242300018');
        $this->useHrblizId = (bool)config('zkteco.use_hrbliz_id', true);
        $this->strictHrblizStatus = (bool)config('zkteco.strict_hrbliz_status', true);
        $this->batchSize = max(1, (int)config('zkteco.batch_size', 50));
        $this->classifier = $classifier ?? app(UmisPunchClassifierService::class);

        // Parse allowed device IDs (supports '27', '27,7,14', or array '[27, 7, 14]')
        $rawDeviceIds = config('zkteco.device_ids', '');
        if (is_array($rawDeviceIds)) {
            $this->allowedDeviceIds = array_values(array_map('strval', $rawDeviceIds));
        } else {
            $trimmed = trim((string)$rawDeviceIds);
            if ($trimmed === '' || $trimmed === '*' || strtolower($trimmed) === 'all') {
                $this->allowedDeviceIds = []; // Empty signifies all permitted
            } else {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $this->allowedDeviceIds = array_values(array_map('strval', $decoded));
                } else {
                    $stripped = trim($trimmed, "[]'\"");
                    $this->allowedDeviceIds = array_values(array_filter(
                        array_map(fn($v) => trim($v, " \t\n\r\0\x0B'\""), explode(',', $stripped)),
                        fn($id) => $id !== ''
                    ));
                }
            }
        }
    }

    /**
     * Clear in-memory caches (useful between test cases or long-running CLI jobs).
     */
    public function clearCache(): void
    {
        $this->tableExistsCache = [];
        $this->deviceLookupCache = [];
        $this->pinMapCache = [];
        $this->classifier?->clearCache();
    }

    /**
     * Check if forwarding is enabled in configuration.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get configured host.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get configured allowed device IDs.
     */
    public function getAllowedDeviceIds(): array
    {
        return $this->allowedDeviceIds;
    }

    /**
     * Get configured batch size.
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Get device_name and serial_number identifiers for all configured allowedDeviceIds.
     * Useful for SQL-level pre-filtering before applying limits (e.g., in zk:sync-punches).
     *
     * @return array<int, string>
     */
    public function getAllowedDeviceIdentifiers(): array
    {
        if (empty($this->allowedDeviceIds) || !$this->hasTable('devices')) {
            return [];
        }

        try {
            $devices = Devices::whereIn('id', $this->allowedDeviceIds)->get(['id', 'device_name', 'serial_number']);
            $identifiers = [];
            foreach ($devices as $dev) {
                $this->cacheDeviceModel($dev);
                if (!empty($dev->device_name)) {
                    $identifiers[] = $dev->device_name;
                }
                if (!empty($dev->serial_number)) {
                    $identifiers[] = $dev->serial_number;
                }
            }
            return array_values(array_unique($identifiers));
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('Error fetching allowed device identifiers: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check whether punches from a given device should be forwarded.
     *
     * @param Devices|int|string|null $device Device model, device ID, serial number, name, or IP
     * @return bool
     */
    public function shouldForwardForDevice(Devices|int|string|null $device): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // If no device restrictions are configured, all devices forward
        if (empty($this->allowedDeviceIds)) {
            return true;
        }

        if ($device === null || $device === '') {
            return false;
        }

        $resolvedId = $this->resolveDeviceId($device);

        return $resolvedId !== null && in_array($resolvedId, $this->allowedDeviceIds, true);
    }

    /**
     * Check whether a device matches the exclusive ZK_FORWARD_DEVICE_IDS list (e.g., device ID 27)
     * that is entitled to the 8-12 / 1-5pm default schedule fallback when no UMIS schedule exists.
     */
    public function isExclusiveForwardDevice(Devices|int|string|null $device): bool
    {
        if (empty($this->allowedDeviceIds)) {
            return true;
        }

        if ($device === null || $device === '') {
            return false;
        }

        $resolvedId = $this->resolveDeviceId($device);

        return $resolvedId !== null && in_array($resolvedId, $this->allowedDeviceIds, true);
    }

    /**
     * Resolve the employee PIN to send to ZKTeco Integration Server (Port 8080).
     * UMIS uses canonical biometric_id; when saving to 192.168.9.243:8080, maps
     * canonical biometric_id -> hrbliz_biometric_id (e.g. 493 -> 5180) if configured.
     * Falls back to original PIN when no hrbliz_biometric_id mapping exists.
     *
     * @param string|int $pin Canonical biometric_id
     * @return string Target PIN for Port 8080 (hrbliz_biometric_id or fallback)
     */
    public function resolveForwardPin(string|int $pin): string
    {
        if (!$this->useHrblizId || !$this->hasTable('biometrics')) {
            return (string)$pin;
        }

        $intPin = (int)$pin;
        if (array_key_exists($intPin, $this->pinMapCache)) {
            return $this->pinMapCache[$intPin];
        }

        try {
            $bio = Biometrics::where('biometric_id', $intPin)->first(['biometric_id', 'hrbliz_biometric_id']);

            if ($bio && !empty($bio->hrbliz_biometric_id)) {
                return $this->pinMapCache[$intPin] = (string)$bio->hrbliz_biometric_id;
            }

            // If incoming pin was already an hrbliz_biometric_id
            if (!$bio) {
                $bioByHrb = Biometrics::where('hrbliz_biometric_id', $intPin)->first(['biometric_id', 'hrbliz_biometric_id']);
                if ($bioByHrb && !empty($bioByHrb->hrbliz_biometric_id)) {
                    return $this->pinMapCache[$intPin] = (string)$bioByHrb->hrbliz_biometric_id;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('Error resolving forward PIN: ' . $e->getMessage());
        }

        return $this->pinMapCache[$intPin] = (string)$pin;
    }

    /**
     * Resolve the status code strictly into 0 (Check-In) or 1 (Check-Out) for Port 8080 / HRBLIZ
     * using the user's UMIS schedule (canonical biometric_id).
     *
     * @param string|int $canonicalPin Canonical UMIS biometric_id
     * @param string $dateTime Timestamp YYYY-MM-DD HH:MM:SS
     * @param int $status Raw punch status from device or caller
     * @param array<int, string> $knownDayPunches Optional other punches today for chronological context
     * @param bool $forceScheduleClassification When true (UMIS devices), always derive 0/1 from UMIS schedule even if raw status is 0 or 1
     * @param bool $allowDefaultScheduleFallback When true (exclusive to ZK_FORWARD_DEVICE_IDS=27), fall back to 08:00-12:00 / 13:00-17:00 if no UMIS schedule is found
     * @return int Strictly 0 (Check-In) or 1 (Check-Out)
     */
    public function resolveForwardStatus(
        string|int $canonicalPin,
        string $dateTime,
        int $status,
        array $knownDayPunches = [],
        bool $forceScheduleClassification = false,
        bool $allowDefaultScheduleFallback = true
    ): int {
        return $this->resolveForwardStatusWithDetails(
            $canonicalPin,
            $dateTime,
            $status,
            $knownDayPunches,
            $forceScheduleClassification,
            $allowDefaultScheduleFallback
        )['status'];
    }

    /**
     * Resolve forward status along with classification metadata (source, slot, schedule_times).
     *
     * @return array{status: int, source: string, slot: ?string, schedule_times: array<int, string>}
     */
    public function resolveForwardStatusWithDetails(
        string|int $canonicalPin,
        string $dateTime,
        int $status,
        array $knownDayPunches = [],
        bool $forceScheduleClassification = false,
        bool $allowDefaultScheduleFallback = true
    ): array {
        if (!$this->strictHrblizStatus && !$forceScheduleClassification) {
            return [
                'status' => $status,
                'source' => 'raw_passthrough',
                'slot' => null,
                'schedule_times' => [],
            ];
        }

        // Preserve explicit 0/1 only when not forcing schedule-based classification (e.g. manual admin overrides)
        if (!$forceScheduleClassification && ($status === 0 || $status === 1)) {
            return [
                'status' => $status,
                'source' => 'explicit_override',
                'slot' => null,
                'schedule_times' => [],
            ];
        }

        // Classify using canonical biometric_id against UMIS schedule rules
        try {
            if ($this->classifier) {
                return $this->classifier->classifyWithDetails(
                    $canonicalPin,
                    $dateTime,
                    $knownDayPunches,
                    $allowDefaultScheduleFallback
                );
            }
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('Classifier error: ' . $e->getMessage());
        }

        // Safe fallback based on time of day
        $hour = (int)date('H', strtotime($dateTime));
        return [
            'status' => ($hour < 12) ? 0 : 1,
            'source' => 'time_of_day_fallback',
            'slot' => null,
            'schedule_times' => [],
        ];
    }

    /**
     * Create base HTTP client with headers, connect timeout, and timeout configured.
     */
    protected function client()
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
            $headers['x-api-key'] = $this->apiKey;
        }

        return Http::withHeaders($headers)
            ->connectTimeout(min($this->timeout, 2))
            ->timeout($this->timeout);
    }

    /**
     * Check connectivity to ZKTeco server health check endpoint.
     *
     * @return array [success => bool, latency_ms => float, data => ?array, error => ?string]
     */
    public function checkHealth(): array
    {
        $start = microtime(true);
        try {
            $response = $this->client()->get("{$this->host}/api/punches");
            $duration = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'latency_ms' => $duration,
                    'data' => $response->json(),
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'latency_ms' => $duration,
                'data' => null,
                'error' => "HTTP {$response->status()}: " . $response->body(),
            ];
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $start) * 1000, 2);
            return [
                'success' => false,
                'latency_ms' => $duration,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Forward a single punch from a UMIS device to the ZKTeco integration server (Port 8080).
     *
     * Flow for ZK_FORWARD_DEVICE_IDS (e.g. device 27):
     * 1) Checks UMIS schedule using canonical biometric_id ($pin) to derive status 0 (In) or 1 (Out)
     *    (falls back to 08:00-12:00 / 13:00-17:00 exclusively for allowed device IDs when no schedule exists).
     * 2) Maps canonical biometric_id -> hrbliz_biometric_id before saving to Port 8080.
     *
     * @param string|int $pin Canonical UMIS biometric_id
     * @param string $dateTime Timestamp YYYY-MM-DD HH:MM:SS
     * @param int $status Raw punch status from device
     * @param Devices|int|string|null $device Device model, ID, name, or SN
     * @param string|null $deviceSn Custom device serial number override
     * @param bool $bypassDeviceFilter When true, ignores allowedDeviceIds filter
     * @param bool $forceScheduleClassification When true (default for device pushes), always classify via UMIS schedule
     * @return bool True if forward succeeded
     */
    public function forwardPunch(
        string|int $pin,
        string $dateTime,
        int $status = 0,
        Devices|int|string|null $device = null,
        ?string $deviceSn = null,
        bool $bypassDeviceFilter = false,
        bool $forceScheduleClassification = true
    ): bool {
        if (!$this->enabled) {
            return false;
        }

        if (!$bypassDeviceFilter && !$this->shouldForwardForDevice($device)) {
            return false;
        }

        $allowDefaultFallback = $this->isExclusiveForwardDevice($device);

        // 1. Check UMIS schedule using canonical biometric_id -> 0 (In) or 1 (Out)
        $classification = $this->resolveForwardStatusWithDetails(
            $pin,
            $dateTime,
            (int)$status,
            [],
            $forceScheduleClassification,
            $allowDefaultFallback
        );
        $targetStatus = $classification['status'];

        // 2. Map canonical biometric_id -> hrbliz_biometric_id for 192.168.9.243:8080
        $targetPin = $this->resolveForwardPin($pin);

        // 3. Resolve device serial number (prefer Devices::serial_number, fallback to ZK_SERVER_DEVICE_SN)
        $sn = $this->resolveDeviceSn($device, $deviceSn);

        try {
            $payload = [
                'pin' => $targetPin,
                'time' => $dateTime,
                'status' => $targetStatus,
                'device_sn' => $sn,
                'created_at' => $dateTime,
                'updated_at' => $dateTime,
            ];

            $response = $this->client()->post("{$this->host}/api/punches", $payload);

            if ($response->successful()) {
                Log::channel('device_logs')->info('ZKTeco punch forwarded successfully', [
                    'canonical_pin' => (string)$pin,
                    'hrbliz_pin' => $targetPin,
                    'date_time' => $dateTime,
                    'raw_status' => (int)$status,
                    'resolved_status' => $targetStatus,
                    'classification_source' => $classification['source'],
                    'classification_slot' => $classification['slot'],
                    'device_sn' => $sn,
                ]);
                return true;
            }

            Log::channel('device_logs')->warning('ZKTeco forward punch failed', [
                'host' => $this->host,
                'payload' => $payload,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::channel('device_logs')->error('ZKTeco forward punch exception', [
                'host' => $this->host,
                'canonical_pin' => (string)$pin,
                'hrbliz_pin' => $targetPin,
                'dateTime' => $dateTime,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Forward a single punch synchronously and return complete transmission details (used by manual punch endpoint).
     *
     * @param string|int $pin Canonical UMIS biometric_id
     * @param string $dateTime Timestamp YYYY-MM-DD HH:MM:SS
     * @param int $status Punch status code (0=In, 1=Out, 255=Auto, etc.)
     * @param Devices|int|string|null $device Device model, ID, name, or SN
     * @param string|null $deviceSn Custom device serial number override
     * @param bool $bypassDeviceFilter When true, ignores allowedDeviceIds filter (for manual inputs)
     * @param bool $forceScheduleClassification When false (default for manual inputs), preserves explicit 0/1
     * @return array
     */
    public function forwardPunchSync(
        string|int $pin,
        string $dateTime,
        int $status = 0,
        Devices|int|string|null $device = null,
        ?string $deviceSn = null,
        bool $bypassDeviceFilter = false,
        bool $forceScheduleClassification = false
    ): array {
        if (!$this->enabled) {
            return [
                'success' => false,
                'enabled' => false,
                'message' => 'ZKTeco forwarding is disabled in configuration.',
                'target_host' => $this->host,
                'status_code' => 0,
                'latency_ms' => 0.0,
                'pin_sent' => (string)$pin,
                'status_sent' => (int)$status,
                'payload' => null,
                'response' => null,
                'error' => 'Forwarding is disabled in configuration (ZK_SERVER_FORWARD_ENABLED=false).',
            ];
        }

        if (!$bypassDeviceFilter && !$this->shouldForwardForDevice($device)) {
            return [
                'success' => false,
                'filtered' => true,
                'message' => 'Device is filtered out by allowed device IDs configuration.',
                'target_host' => $this->host,
                'allowed_devices' => $this->allowedDeviceIds,
                'status_code' => 0,
                'latency_ms' => 0.0,
                'pin_sent' => (string)$pin,
                'status_sent' => (int)$status,
                'payload' => null,
                'response' => null,
                'error' => 'Device ID is not in allowed list for ZKTeco forwarding.',
            ];
        }

        $allowDefaultFallback = $bypassDeviceFilter || $this->isExclusiveForwardDevice($device);

        // Resolve status using canonical biometric_id
        $classification = $this->resolveForwardStatusWithDetails(
            $pin,
            $dateTime,
            (int)$status,
            [],
            $forceScheduleClassification,
            $allowDefaultFallback
        );
        $targetStatus = $classification['status'];

        // Map canonical biometric_id -> hrbliz_biometric_id for Port 8080
        $targetPin = $this->resolveForwardPin($pin);

        // Resolve device serial number
        $sn = $this->resolveDeviceSn($device, $deviceSn);

        $payload = [
            'pin' => $targetPin,
            'time' => $dateTime,
            'status' => $targetStatus,
            'device_sn' => $sn,
            'created_at' => $dateTime,
            'updated_at' => $dateTime,
        ];

        $start = microtime(true);
        try {
            $response = $this->client()->post("{$this->host}/api/punches", $payload);
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful()) {
                Log::channel('device_logs')->info('ZKTeco punch forwarded synchronously', [
                    'canonical_pin' => (string)$pin,
                    'hrbliz_pin' => $targetPin,
                    'date_time' => $dateTime,
                    'resolved_status' => $targetStatus,
                    'classification_source' => $classification['source'],
                    'device_sn' => $sn,
                    'latency_ms' => $latencyMs,
                ]);

                return [
                    'success' => true,
                    'target_host' => $this->host,
                    'status_code' => $response->status(),
                    'latency_ms' => $latencyMs,
                    'pin_sent' => $targetPin,
                    'status_sent' => $targetStatus,
                    'classification_source' => $classification['source'],
                    'payload' => $payload,
                    'response' => $response->json() ?? ['raw' => $response->body()],
                    'error' => null,
                ];
            }

            Log::channel('device_logs')->warning('ZKTeco sync forward punch failed', [
                'host' => $this->host,
                'payload' => $payload,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'target_host' => $this->host,
                'status_code' => $response->status(),
                'latency_ms' => $latencyMs,
                'pin_sent' => $targetPin,
                'status_sent' => $targetStatus,
                'payload' => $payload,
                'response' => $response->json() ?? ['raw' => $response->body()],
                'error' => "HTTP {$response->status()}: " . $response->body(),
            ];
        } catch (\Throwable $e) {
            $latencyMs = round((microtime(true) - $start) * 1000, 2);
            Log::channel('device_logs')->error('ZKTeco sync forward punch exception', [
                'host' => $this->host,
                'canonical_pin' => (string)$pin,
                'hrbliz_pin' => $targetPin,
                'dateTime' => $dateTime,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'target_host' => $this->host,
                'status_code' => 0,
                'latency_ms' => $latencyMs,
                'pin_sent' => $targetPin,
                'status_sent' => $targetStatus,
                'payload' => $payload,
                'response' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Filter, preload DB context, classify status using canonical UMIS biometric_id,
     * and map canonical PIN -> hrbliz_biometric_id for a batch of punches in a single pass.
     *
     * @param array<int, array<string, mixed>> $punches
     * @param Devices|int|string|null $device Common device
     * @param bool $bypassDeviceFilter When true, skips allowedDeviceIds check
     * @param bool $forceScheduleClassification When true, derives 0/1 strictly from UMIS schedule
     * @return array{payloads: array<int, array<string, mixed>>, preview: array<int, array<string, mixed>>, filtered_out: int}
     */
    public function prepareBatch(
        array $punches,
        Devices|int|string|null $device = null,
        bool $bypassDeviceFilter = false,
        bool $forceScheduleClassification = true
    ): array {
        if (empty($punches)) {
            return ['payloads' => [], 'preview' => [], 'filtered_out' => 0];
        }

        if (!$bypassDeviceFilter && $device !== null && !$this->shouldForwardForDevice($device)) {
            return ['payloads' => [], 'preview' => [], 'filtered_out' => count($punches)];
        }

        // Preload allowed devices once so per-item checks never query DB repeatedly
        $this->preloadDevicesForBatch($punches, $device);

        // 1. Filter eligible punches & extract canonical PIN + timestamp
        $eligible = [];
        $filteredOut = 0;

        foreach ($punches as $punch) {
            if (!is_array($punch)) {
                continue;
            }

            $itemDevice = $punch['device'] ?? $punch['device_id'] ?? $punch['device_name'] ?? $punch['device_sn'] ?? $device;

            // Strict null-safe filter: when allowedDeviceIds is set, null/unknown device MUST be rejected
            if (!$bypassDeviceFilter && !$this->shouldForwardForDevice($itemDevice)) {
                $filteredOut++;
                continue;
            }

            $canonicalPin = $punch['canonical_pin'] ?? $punch['biometric_id'] ?? $punch['pin'] ?? $punch['hrbliz_biometric_id'] ?? null;
            $time = $punch['time'] ?? $punch['date_time'] ?? null;
            if (!$time && !empty($punch['dtr_date']) && !empty($punch['dtr_time'])) {
                $time = trim((string)$punch['dtr_date']) . ' ' . trim((string)$punch['dtr_time']);
            }

            if ($canonicalPin === null || trim((string)$canonicalPin) === '' || !$time) {
                continue;
            }

            $time = trim((string)$time);
            $eligible[] = [
                'canonical_pin' => (string)$canonicalPin,
                'time' => $time,
                'date' => substr($time, 0, 10),
                'raw_status' => (int)($punch['status'] ?? $punch['dtr_type'] ?? 0),
                'item_device' => $itemDevice,
                'explicit_sn' => $punch['serial_number'] ?? $punch['device_sn'] ?? null,
                'source' => $punch['source'] ?? null,
            ];
        }

        if (empty($eligible)) {
            return ['payloads' => [], 'preview' => [], 'filtered_out' => $filteredOut];
        }

        // 2. Sort chronologically so accumulated same-day punches are ordered properly
        usort($eligible, fn($a, $b) => strcmp($a['time'], $b['time']));

        // 3. Bulk preload biometrics (canonical -> hrbliz_biometric_id) and UMIS schedules + day punches
        $canonicalPins = array_values(array_unique(array_map(fn($e) => (int)$e['canonical_pin'], $eligible)));
        $this->preloadBiometricPinMap($canonicalPins);

        $pinDatePairs = array_map(fn($e) => [
            'pin' => (int)$e['canonical_pin'],
            'date' => $e['date'],
        ], $eligible);
        $this->classifier?->preloadBatchContext($pinDatePairs);

        // 4. Group all same-day timestamps per canonical PIN so classification sees full-day batch context
        $dayPunchesByPinDate = [];
        foreach ($eligible as $entry) {
            $pinDateKey = "{$entry['canonical_pin']}_{$entry['date']}";
            $dayPunchesByPinDate[$pinDateKey][] = $entry['time'];
        }

        // 5. Classify and build payloads in a single pass
        $payloads = [];
        $preview = [];

        foreach ($eligible as $entry) {
            $canonicalPin = $entry['canonical_pin'];
            $time = $entry['time'];
            $pinDateKey = "{$canonicalPin}_{$entry['date']}";

            $allowDefaultFallback = $bypassDeviceFilter || $this->isExclusiveForwardDevice($entry['item_device']);

            // Classify status using canonical UMIS biometric_id
            $classification = $this->resolveForwardStatusWithDetails(
                $canonicalPin,
                $time,
                $entry['raw_status'],
                $dayPunchesByPinDate[$pinDateKey] ?? [$time],
                $forceScheduleClassification,
                $allowDefaultFallback
            );
            $targetStatus = $classification['status'];

            // Map canonical biometric_id -> hrbliz_biometric_id for 192.168.9.243:8080
            $targetPin = $this->resolveForwardPin($canonicalPin);

            // Resolve device serial number
            $sn = $this->resolveDeviceSn($entry['item_device'], $entry['explicit_sn']);

            $payloads[] = [
                'pin' => $targetPin,
                'time' => $time,
                'status' => $targetStatus,
                'device_sn' => $sn,
                'created_at' => $time,
                'updated_at' => $time,
            ];

            $preview[] = [
                'canonical_pin' => $canonicalPin,
                'pin' => $targetPin,
                'time' => $time,
                'raw_status' => $entry['raw_status'],
                'status' => $targetStatus,
                'classification_source' => $classification['source'],
                'classification_slot' => $classification['slot'],
                'device_sn' => $sn,
                'source' => $entry['source'],
            ];
        }

        return [
            'payloads' => $payloads,
            'preview' => $preview,
            'filtered_out' => $filteredOut,
        ];
    }

    /**
     * Transmit already-prepared payloads in chunks of ZK_SERVER_BATCH_SIZE (default 50).
     * Fails fast on connection timeouts/unreachable host to avoid cascading stalls.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @return array{success: bool, total: int, inserted: int, skipped: int, errors: array<int, string>}
     */
    public function sendPreparedBatch(array $payloads): array
    {
        if (empty($payloads)) {
            return [
                'success' => true,
                'total' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }

        if (!$this->enabled) {
            return [
                'success' => false,
                'message' => 'ZKTeco forwarding is disabled in configuration.',
                'total' => count($payloads),
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['Forwarding disabled'],
            ];
        }

        $chunks = array_chunk($payloads, $this->batchSize);
        $totalInserted = 0;
        $totalSkipped = 0;
        $errors = [];

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                $response = $this->client()->post("{$this->host}/api/punches", $chunk);

                if ($response->successful()) {
                    $resJson = $response->json();
                    $totalInserted += (int)($resJson['inserted_count'] ?? count($chunk));
                    $totalSkipped += (int)($resJson['skipped_count'] ?? 0);
                } else {
                    $errors[] = "HTTP {$response->status()}: " . $response->body();
                    Log::channel('device_logs')->warning('ZKTeco batch forward failed', [
                        'chunk' => $chunkIndex + 1,
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                }
            } catch (ConnectionException $e) {
                $errors[] = 'Connection failed (remaining chunks aborted): ' . $e->getMessage();
                Log::channel('device_logs')->error('ZKTeco batch forward connection error (aborting remaining chunks): ' . $e->getMessage());
                break;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                Log::channel('device_logs')->error('ZKTeco batch forward exception: ' . $e->getMessage());
            }
        }

        return [
            'success' => empty($errors),
            'total' => count($payloads),
            'inserted' => $totalInserted,
            'skipped' => $totalSkipped,
            'errors' => $errors,
        ];
    }

    /**
     * Forward a batch of punches to the ZKTeco integration server (Port 8080).
     *
     * @param array<int, array<string, mixed>> $punches
     * @param Devices|int|string|null $device Optional common device
     * @param bool $bypassDeviceFilter When true, ignores allowedDeviceIds filter (for manual inputs)
     * @param bool $forceScheduleClassification When true, classifies 0/1 strictly via UMIS schedule
     * @return array{success: bool, total: int, inserted: int, skipped: int, errors: array<int, string>}
     */
    public function forwardBatch(
        array $punches,
        Devices|int|string|null $device = null,
        bool $bypassDeviceFilter = false,
        bool $forceScheduleClassification = true
    ): array {
        if (empty($punches)) {
            return [
                'success' => true,
                'total' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }

        if (!$this->enabled) {
            return [
                'success' => false,
                'message' => 'ZKTeco forwarding is disabled in configuration.',
                'total' => count($punches),
                'inserted' => 0,
                'skipped' => 0,
                'errors' => ['Forwarding disabled'],
            ];
        }

        $prepared = $this->prepareBatch($punches, $device, $bypassDeviceFilter, $forceScheduleClassification);

        if (empty($prepared['payloads'])) {
            return [
                'success' => true,
                'message' => 'No punches matched device filter or valid format.',
                'total' => 0,
                'inserted' => 0,
                'skipped' => $prepared['filtered_out'],
                'errors' => [],
            ];
        }

        return $this->sendPreparedBatch($prepared['payloads']);
    }

    /**
     * Bulk-preload canonical -> hrbliz_biometric_id mappings in one query.
     *
     * @param array<int, int> $pins
     */
    protected function preloadBiometricPinMap(array $pins): void
    {
        if (!$this->useHrblizId || empty($pins) || !$this->hasTable('biometrics')) {
            return;
        }

        $missing = array_values(array_filter($pins, fn($p) => !array_key_exists($p, $this->pinMapCache)));
        if (empty($missing)) {
            return;
        }

        try {
            $rows = Biometrics::whereIn('biometric_id', $missing)
                ->orWhereIn('hrbliz_biometric_id', $missing)
                ->get(['biometric_id', 'hrbliz_biometric_id']);

            $byCanonical = [];
            $byHrbliz = [];
            foreach ($rows as $row) {
                if (!empty($row->biometric_id)) {
                    $byCanonical[(int)$row->biometric_id] = $row;
                }
                if (!empty($row->hrbliz_biometric_id)) {
                    $byHrbliz[(int)$row->hrbliz_biometric_id] = $row;
                }
            }

            foreach ($missing as $pin) {
                if (isset($byCanonical[$pin]) && !empty($byCanonical[$pin]->hrbliz_biometric_id)) {
                    $this->pinMapCache[$pin] = (string)$byCanonical[$pin]->hrbliz_biometric_id;
                } elseif (!isset($byCanonical[$pin]) && isset($byHrbliz[$pin]) && !empty($byHrbliz[$pin]->hrbliz_biometric_id)) {
                    $this->pinMapCache[$pin] = (string)$byHrbliz[$pin]->hrbliz_biometric_id;
                } else {
                    $this->pinMapCache[$pin] = (string)$pin;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('Error preloading biometric PIN map: ' . $e->getMessage());
        }
    }

    /**
     * Preload devices referenced in a batch to avoid per-item device lookups.
     */
    protected function preloadDevicesForBatch(array $punches, Devices|int|string|null $commonDevice): void
    {
        if (!$this->hasTable('devices')) {
            return;
        }

        if ($commonDevice instanceof Devices) {
            $this->cacheDeviceModel($commonDevice);
        }

        $ids = [];
        $strings = [];

        if (is_numeric($commonDevice)) {
            $ids[(int)$commonDevice] = true;
        } elseif (is_string($commonDevice) && trim($commonDevice) !== '') {
            $strings[trim($commonDevice)] = true;
        }

        foreach ($punches as $punch) {
            if (!is_array($punch)) {
                continue;
            }
            if (($punch['device'] ?? null) instanceof Devices) {
                $this->cacheDeviceModel($punch['device']);
            }
            if (isset($punch['device_id']) && is_numeric($punch['device_id'])) {
                $ids[(int)$punch['device_id']] = true;
            }
            foreach (['device_name', 'device_sn', 'serial_number'] as $field) {
                if (!empty($punch[$field]) && is_string($punch[$field])) {
                    $strings[trim($punch[$field])] = true;
                }
            }
        }

        $missingIds = array_values(array_filter(array_keys($ids), fn($id) => !array_key_exists("id:{$id}", $this->deviceLookupCache)));
        $missingStrings = array_values(array_filter(array_keys($strings), fn($s) => !array_key_exists("str:{$s}", $this->deviceLookupCache)));

        if (empty($missingIds) && empty($missingStrings)) {
            return;
        }

        try {
            $query = Devices::query();
            $hasCondition = false;

            if (!empty($missingIds)) {
                $query->whereIn('id', $missingIds);
                $hasCondition = true;
            }

            if (!empty($missingStrings)) {
                $method = $hasCondition ? 'orWhereIn' : 'whereIn';
                $query->{$method}('serial_number', $missingStrings)
                    ->orWhereIn('device_name', $missingStrings)
                    ->orWhereIn('ip_address', $missingStrings);
            }

            // Initialize misses as null first so unmatched strings won't trigger repeated DB queries
            foreach ($missingIds as $id) {
                $this->deviceLookupCache["id:{$id}"] = null;
            }
            foreach ($missingStrings as $str) {
                $this->deviceLookupCache["str:{$str}"] = null;
            }

            foreach ($query->get() as $dev) {
                $this->cacheDeviceModel($dev);
            }
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('Error preloading devices for batch: ' . $e->getMessage());
        }
    }

    /**
     * Resolve device primary ID from a Devices model, numeric ID, serial number, name, or IP.
     */
    protected function resolveDeviceId(Devices|int|string|null $device): ?string
    {
        if ($device instanceof Devices) {
            $this->cacheDeviceModel($device);
            return (string)$device->id;
        }

        if (is_numeric($device)) {
            return (string)(int)$device;
        }

        if (is_string($device) && trim($device) !== '') {
            $matched = $this->resolveDeviceModel($device);
            return $matched ? (string)$matched->id : null;
        }

        return null;
    }

    /**
     * Resolve a Devices model with in-memory caching.
     */
    protected function resolveDeviceModel(Devices|int|string|null $device): ?Devices
    {
        if ($device instanceof Devices) {
            $this->cacheDeviceModel($device);
            return $device;
        }

        if (!$this->hasTable('devices')) {
            return null;
        }

        if (is_numeric($device)) {
            $key = 'id:' . (int)$device;
            if (!array_key_exists($key, $this->deviceLookupCache)) {
                $found = Devices::find((int)$device);
                $this->deviceLookupCache[$key] = $found;
                if ($found) {
                    $this->cacheDeviceModel($found);
                }
            }
            return $this->deviceLookupCache[$key];
        }

        if (is_string($device) && trim($device) !== '') {
            $trimmed = trim($device);
            $key = 'str:' . $trimmed;
            if (!array_key_exists($key, $this->deviceLookupCache)) {
                $found = Devices::where('serial_number', $trimmed)
                    ->orWhere('device_name', $trimmed)
                    ->orWhere('ip_address', $trimmed)
                    ->first();
                $this->deviceLookupCache[$key] = $found;
                if ($found) {
                    $this->cacheDeviceModel($found);
                }
            }
            return $this->deviceLookupCache[$key];
        }

        return null;
    }

    /**
     * Store a Devices model under all its lookup keys.
     */
    protected function cacheDeviceModel(Devices $device): void
    {
        $this->deviceLookupCache['id:' . (int)$device->id] = $device;
        if (!empty($device->serial_number)) {
            $this->deviceLookupCache['str:' . trim((string)$device->serial_number)] = $device;
        }
        if (!empty($device->device_name)) {
            $this->deviceLookupCache['str:' . trim((string)$device->device_name)] = $device;
        }
        if (!empty($device->ip_address)) {
            $this->deviceLookupCache['str:' . trim((string)$device->ip_address)] = $device;
        }
    }

    /**
     * Resolve the device serial number to transmit to Port 8080.
     * Prefers the Devices model's serial_number, falls back to explicit SN or ZK_SERVER_DEVICE_SN.
     */
    protected function resolveDeviceSn(Devices|int|string|null $device, ?string $explicitSn = null): string
    {
        $model = $this->resolveDeviceModel($device);
        if (!$model && !empty($explicitSn)) {
            $model = $this->resolveDeviceModel($explicitSn);
        }

        if ($model && !empty($model->serial_number)) {
            return trim((string)$model->serial_number);
        }

        if (!empty($explicitSn) && trim($explicitSn) !== '') {
            return trim($explicitSn);
        }

        if ($model && !empty($model->device_name)) {
            return trim((string)$model->device_name);
        }

        if (is_string($device) && trim($device) !== '' && !is_numeric($device)) {
            return trim($device);
        }

        return $this->defaultDeviceSn;
    }

    /**
     * Cached table existence check.
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
