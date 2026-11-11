<?php

namespace App\Services;

use App\Models\Biometrics;
use App\Models\Devices;
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
    protected ?UmisPunchClassifierService $classifier;

    public function __construct(?UmisPunchClassifierService $classifier = null)
    {
        $this->enabled = (bool)config('zkteco.forward_enabled', true);
        $this->host = rtrim(config('zkteco.host', 'http://127.0.0.1:8080'), '/');
        $this->timeout = (int)config('zkteco.timeout', 3);
        $this->apiKey = config('zkteco.api_key');
        $this->defaultDeviceSn = config('zkteco.device_sn', 'RKQ4242300018');
        $this->useHrblizId = (bool)config('zkteco.use_hrbliz_id', true);
        $this->strictHrblizStatus = (bool)config('zkteco.strict_hrbliz_status', true);
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
     * Check whether punches from a given device should be forwarded.
     *
     * @param Devices|int|string|null $device Device model, device ID, serial number, or IP
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

        if ($device === null) {
            return false;
        }

        $resolvedId = null;
        if ($device instanceof Devices) {
            $resolvedId = (string)$device->id;
        } elseif (is_numeric($device)) {
            $resolvedId = (string)(int)$device;
        } elseif (is_string($device) && !empty($device)) {
            $matched = Schema::hasTable('devices')
                ? Devices::where('serial_number', $device)
                    ->orWhere('device_name', $device)
                    ->orWhere('ip_address', $device)
                    ->first()
                : null;
            if ($matched) {
                $resolvedId = (string)$matched->id;
            }
        }

        return $resolvedId !== null && in_array($resolvedId, $this->allowedDeviceIds, true);
    }

    /**
     * Resolve the employee PIN to send to ZKTeco Integration Server.
     * If use_hrbliz_id is enabled and employee has an hrbliz_biometric_id in biometrics,
     * returns hrbliz_biometric_id (e.g. 5180 instead of 493).
     * Otherwise falls back to original PIN.
     *
     * @param string|int $pin Canonical biometric_id or incoming PIN
     * @return string
     */
    public function resolveForwardPin(string|int $pin): string
    {
        if (!$this->useHrblizId || !Schema::hasTable('biometrics')) {
            return (string)$pin;
        }

        try {
            $intPin = (int)$pin;
            $bio = Biometrics::where('biometric_id', $intPin)->first();

            // If found by canonical biometric_id and hrbliz_biometric_id is set
            if ($bio && !empty($bio->hrbliz_biometric_id)) {
                return (string)$bio->hrbliz_biometric_id;
            }

            // If incoming pin was already the hrbliz_biometric_id
            if (!$bio) {
                $bioByHrb = Biometrics::where('hrbliz_biometric_id', $intPin)->first();
                if ($bioByHrb && !empty($bioByHrb->hrbliz_biometric_id)) {
                    return (string)$bioByHrb->hrbliz_biometric_id;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('Error resolving forward PIN: ' . $e->getMessage());
        }

        return (string)$pin;
    }

    /**
     * Resolve the status code (0 for In, 1 for Out) strictly for HRBLIZ.
     * If incoming punch has status 255 (neutral punch) and strict_hrbliz_status is enabled,
     * uses UmisPunchClassifierService to classify into 0 (Check-In) or 1 (Check-Out)
     * using the employee's assigned UMIS shift windows.
     *
     * @param string|int $canonicalPin
     * @param string $dateTime
     * @param int $status
     * @param array $knownDayPunches Optional list of other punches today to maintain chronological context
     * @return int Strictly 0 (Check-In) or 1 (Check-Out)
     */
    public function resolveForwardStatus(
        string|int $canonicalPin,
        string $dateTime,
        int $status,
        array $knownDayPunches = []
    ): int {
        if (!$this->strictHrblizStatus) {
            return $status;
        }

        // If status is already strictly 0 or 1, preserve it
        if ($status === 0 || $status === 1) {
            return $status;
        }

        // Classify status 255 (or any non-0/1 code) using UMIS schedule rules
        try {
            if ($this->classifier) {
                return $this->classifier->classifyStatus($canonicalPin, $dateTime, $knownDayPunches);
            }
        } catch (\Throwable $e) {
            Log::channel('device_logs')->debug('Classifier error: ' . $e->getMessage());
        }

        // Safe fallback based on time of day
        $hour = (int)date('H', strtotime($dateTime));
        return ($hour < 12) ? 0 : 1;
    }

    /**
     * Create base HTTP client with headers and timeout configured.
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
     * Forward a single punch to the ZKTeco integration server.
     *
     * @param string|int $pin Employee biometric ID / PIN
     * @param string $dateTime Timestamp YYYY-MM-DD HH:MM:SS
     * @param int $status Punch status code (0=In, 1=Out, etc.)
     * @param Devices|int|string|null $device Device model, ID, or SN
     * @param string|null $deviceSn Custom device identifier override
     * @param bool $bypassDeviceFilter When true, ignores allowedDeviceIds filter (for manual inputs)
     * @return bool True if forward was successful or duplicate was handled
     */
    public function forwardPunch(
        string|int $pin,
        string $dateTime,
        int $status = 0,
        Devices|int|string|null $device = null,
        ?string $deviceSn = null,
        bool $bypassDeviceFilter = false
    ): bool {
        if (!$this->enabled) {
            return false;
        }

        // Check if device is allowed (if device restrictions are configured)
        if (!$bypassDeviceFilter && !$this->shouldForwardForDevice($device)) {
            return false;
        }

        // Map canonical biometric_id -> hrbliz_biometric_id (e.g. 493 -> 5180)
        $targetPin = $this->resolveForwardPin($pin);

        // Resolve status to strictly 0 (In) or 1 (Out) for HRBLIZ
        $targetStatus = $this->resolveForwardStatus($pin, $dateTime, (int)$status);

        $sn = $deviceSn;
        if (!$sn && $device instanceof Devices) {
            $sn = $device->serial_number ?: $device->device_name;
        } elseif (!$sn && is_string($device) && Schema::hasTable('devices')) {
            $matchedDev = Devices::where('serial_number', $device)
                ->orWhere('device_name', $device)
                ->first();
            $sn = $matchedDev?->serial_number ?: $device;
        } elseif (!$sn && is_string($device)) {
            $sn = $device;
        }

        try {
            $payload = [
                'pin' => $targetPin,
                'time' => $dateTime,
                'status' => $targetStatus,
                'device_sn' => $sn ?: $this->defaultDeviceSn,
                'created_at' => $dateTime,
                'updated_at' => $dateTime,
            ];

            $response = $this->client()->post("{$this->host}/api/punches", $payload);

            if ($response->successful()) {
                Log::channel('device_logs')->info('ZKTeco punch forwarded successfully', [
                    'original_pin' => $pin,
                    'target_pin' => $targetPin,
                    'date_time' => $dateTime,
                    'device' => $sn,
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
                'pin' => $targetPin,
                'dateTime' => $dateTime,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Forward a single punch synchronously and return complete transmission details.
     *
     * @param string|int $pin Employee biometric ID / PIN
     * @param string $dateTime Timestamp YYYY-MM-DD HH:MM:SS
     * @param int $status Punch status code (0=In, 1=Out, etc.)
     * @param Devices|int|string|null $device Device model, ID, or SN
     * @param string|null $deviceSn Custom device identifier override
     * @param bool $bypassDeviceFilter When true, ignores allowedDeviceIds filter (for manual inputs)
     * @return array [success => bool, target_host => string, status_code => int, latency_ms => float, pin_sent => string, status_sent => int, payload => array, response => ?array, error => ?string]
     */
    public function forwardPunchSync(
        string|int $pin,
        string $dateTime,
        int $status = 0,
        Devices|int|string|null $device = null,
        ?string $deviceSn = null,
        bool $bypassDeviceFilter = false
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

        // Check if device is allowed (if device restrictions are configured and bypass is not set)
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

        // Map canonical biometric_id -> hrbliz_biometric_id (e.g. 493 -> 5180)
        $targetPin = $this->resolveForwardPin($pin);

        // Resolve status to strictly 0 (In) or 1 (Out) for HRBLIZ
        $targetStatus = $this->resolveForwardStatus($pin, $dateTime, (int)$status);

        $sn = $deviceSn;
        if (!$sn && $device instanceof Devices) {
            $sn = $device->serial_number ?: $device->device_name;
        } elseif (!$sn && is_string($device) && Schema::hasTable('devices')) {
            $matchedDev = Devices::where('serial_number', $device)
                ->orWhere('device_name', $device)
                ->first();
            $sn = $matchedDev?->serial_number ?: $device;
        } elseif (!$sn && is_string($device)) {
            $sn = $device;
        }

        $payload = [
            'pin' => $targetPin,
            'time' => $dateTime,
            'status' => $targetStatus,
            'device_sn' => $sn ?: $this->defaultDeviceSn,
            'created_at' => $dateTime,
            'updated_at' => $dateTime,
        ];

        $start = microtime(true);
        try {
            $response = $this->client()->post("{$this->host}/api/punches", $payload);
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful()) {
                Log::channel('device_logs')->info('ZKTeco punch forwarded synchronously', [
                    'original_pin' => $pin,
                    'target_pin' => $targetPin,
                    'date_time' => $dateTime,
                    'device' => $sn,
                    'latency_ms' => $latencyMs,
                ]);

                return [
                    'success' => true,
                    'target_host' => $this->host,
                    'status_code' => $response->status(),
                    'latency_ms' => $latencyMs,
                    'pin_sent' => $targetPin,
                    'status_sent' => $targetStatus,
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
                'pin' => $targetPin,
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
     * Forward a batch of punches to the ZKTeco integration server.
     *
     * @param array $punches Array of associative arrays [['pin' => ..., 'time' => ..., 'status' => ..., 'device_sn' => ..., 'device_id' => ...]]
     * @param Devices|int|string|null $device Optional common device
     * @param bool $bypassDeviceFilter When true, ignores allowedDeviceIds filter (for manual inputs)
     * @return array [success => bool, total => int, inserted => int, skipped => int, errors => array]
     */
    public function forwardBatch(
        array $punches,
        Devices|int|string|null $device = null,
        bool $bypassDeviceFilter = false
    ): array
    {
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

        // If common device given and restricted, check it
        if (!$bypassDeviceFilter && $device !== null && !$this->shouldForwardForDevice($device)) {
            return [
                'success' => true,
                'total' => 0,
                'inserted' => 0,
                'skipped' => count($punches),
                'errors' => [],
            ];
        }

        // Sort input punches chronologically by timestamp
        usort($punches, function ($a, $b) {
            $tA = $a['time'] ?? $a['date_time'] ?? ($a['dtr_date'] ?? '') . ' ' . ($a['dtr_time'] ?? '');
            $tB = $b['time'] ?? $b['date_time'] ?? ($b['dtr_date'] ?? '') . ' ' . ($b['dtr_time'] ?? '');
            return strcmp(trim($tA), trim($tB));
        });

        $formatted = [];
        $accumulatedByPinDate = [];

        foreach ($punches as $punch) {
            // Check per-item device filter if item has device_id or device_name
            $itemDevice = $punch['device_id'] ?? $punch['device_sn'] ?? $punch['device_name'] ?? $device;
            if (!$bypassDeviceFilter && $itemDevice !== null && !$this->shouldForwardForDevice($itemDevice)) {
                continue;
            }

            $rawPin = $punch['pin'] ?? $punch['biometric_id'] ?? $punch['hrbliz_biometric_id'] ?? null;
            $time = $punch['time'] ?? $punch['date_time'] ?? null;
            if (!$time && !empty($punch['dtr_date']) && !empty($punch['dtr_time'])) {
                $time = trim($punch['dtr_date']) . ' ' . trim($punch['dtr_time']);
            }

            if ($rawPin === null || !$time) {
                continue;
            }

            // Resolve 493 -> 5180 if mapped
            $targetPin = $this->resolveForwardPin($rawPin);

            // Group accumulated punches by PIN and Date for correct chronological context
            $punchDate = substr(trim($time), 0, 10);
            $pinDateKey = "{$rawPin}_{$punchDate}";
            if (!isset($accumulatedByPinDate[$pinDateKey])) {
                $accumulatedByPinDate[$pinDateKey] = [];
            }
            $accumulatedByPinDate[$pinDateKey][] = $time;

            // Resolve status to strictly 0 (In) or 1 (Out) for HRBLIZ with accumulated context
            $rawStatus = (int)($punch['status'] ?? $punch['dtr_type'] ?? 0);
            $targetStatus = $this->resolveForwardStatus($rawPin, $time, $rawStatus, $accumulatedByPinDate[$pinDateKey]);

            $itemSn = $punch['device_sn'] ?? $punch['serial_number'] ?? null;
            if (!$itemSn && !empty($punch['device_id']) && Schema::hasTable('devices')) {
                $devModel = Devices::find($punch['device_id']);
                $itemSn = $devModel?->serial_number ?: $devModel?->device_name;
            }
            if (!$itemSn && !empty($punch['device_name']) && Schema::hasTable('devices')) {
                $devModel = Devices::where('serial_number', $punch['device_name'])
                    ->orWhere('device_name', $punch['device_name'])
                    ->first();
                $itemSn = $devModel?->serial_number ?: $punch['device_name'];
            }

            $formatted[] = [
                'pin' => $targetPin,
                'time' => $time,
                'status' => $targetStatus,
                'device_sn' => $itemSn ?: $this->defaultDeviceSn,
                'created_at' => $time,
                'updated_at' => $time,
            ];
        }

        if (empty($formatted)) {
            return [
                'success' => true,
                'message' => 'No punches matched device filter or valid format.',
                'total' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }

        $batchSize = (int)config('zkteco.batch_size', 50);
        $chunks = array_chunk($formatted, $batchSize);
        $totalInserted = 0;
        $totalSkipped = 0;
        $errors = [];

        foreach ($chunks as $chunk) {
            try {
                $response = $this->client()->post("{$this->host}/api/punches", $chunk);

                if ($response->successful()) {
                    $resJson = $response->json();
                    $totalInserted += ($resJson['inserted_count'] ?? count($chunk));
                    $totalSkipped += ($resJson['skipped_count'] ?? 0);
                } else {
                    $errors[] = "HTTP {$response->status()}: " . $response->body();
                    Log::channel('device_logs')->warning('ZKTeco batch forward failed', [
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                Log::channel('device_logs')->error('ZKTeco batch forward exception: ' . $e->getMessage());
            }
        }

        return [
            'success' => empty($errors),
            'total' => count($formatted),
            'inserted' => $totalInserted,
            'skipped' => $totalSkipped,
            'errors' => $errors,
        ];
    }
}
