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

    public function __construct()
    {
        $this->enabled = (bool)config('zkteco.forward_enabled', true);
        $this->host = rtrim(config('zkteco.host', 'http://127.0.0.1:8080'), '/');
        $this->timeout = (int)config('zkteco.timeout', 3);
        $this->apiKey = config('zkteco.api_key');
        $this->defaultDeviceSn = config('zkteco.device_sn', 'DTRSERVICE_8181');
        $this->useHrblizId = (bool)config('zkteco.use_hrbliz_id', true);

        // Parse allowed device IDs (e.g. '7' or '7,14,18')
        $rawDeviceIds = trim((string)config('zkteco.device_ids', ''));
        if ($rawDeviceIds === '' || $rawDeviceIds === '*' || strtolower($rawDeviceIds) === 'all') {
            $this->allowedDeviceIds = []; // Empty signifies all permitted
        } else {
            $this->allowedDeviceIds = array_values(array_filter(
                array_map('trim', explode(',', $rawDeviceIds)),
                fn($id) => $id !== ''
            ));
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
     * @return bool True if forward was successful or duplicate was handled
     */
    public function forwardPunch(
        string|int $pin,
        string $dateTime,
        int $status = 0,
        Devices|int|string|null $device = null,
        ?string $deviceSn = null
    ): bool {
        if (!$this->enabled) {
            return false;
        }

        // Check if device is allowed (if device restrictions are configured)
        if (!$this->shouldForwardForDevice($device)) {
            return false;
        }

        // Map canonical biometric_id -> hrbliz_biometric_id (e.g. 493 -> 5180)
        $targetPin = $this->resolveForwardPin($pin);

        $sn = $deviceSn;
        if (!$sn && $device instanceof Devices) {
            $sn = $device->serial_number ?: $device->device_name;
        } elseif (!$sn && is_string($device)) {
            $sn = $device;
        }

        try {
            $payload = [
                'pin' => $targetPin,
                'time' => $dateTime,
                'status' => (int)$status,
                'device_sn' => $sn ?: $this->defaultDeviceSn,
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
     * Forward a batch of punches to the ZKTeco integration server.
     *
     * @param array $punches Array of associative arrays [['pin' => ..., 'time' => ..., 'status' => ..., 'device_sn' => ..., 'device_id' => ...]]
     * @param Devices|int|string|null $device Optional common device
     * @return array [success => bool, total => int, inserted => int, skipped => int, errors => array]
     */
    public function forwardBatch(array $punches, Devices|int|string|null $device = null): array
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
        if ($device !== null && !$this->shouldForwardForDevice($device)) {
            return [
                'success' => true,
                'total' => 0,
                'inserted' => 0,
                'skipped' => count($punches),
                'errors' => [],
            ];
        }

        $formatted = [];
        foreach ($punches as $punch) {
            // Check per-item device filter if item has device_id or device_name
            $itemDevice = $punch['device_id'] ?? $punch['device_sn'] ?? $punch['device_name'] ?? $device;
            if ($itemDevice !== null && !$this->shouldForwardForDevice($itemDevice)) {
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

            $formatted[] = [
                'pin' => $targetPin,
                'time' => $time,
                'status' => (int)($punch['status'] ?? $punch['dtr_type'] ?? 0),
                'device_sn' => $punch['device_sn'] ?? $punch['device_name'] ?? $this->defaultDeviceSn,
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
