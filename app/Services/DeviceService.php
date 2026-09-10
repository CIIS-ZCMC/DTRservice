<?php

namespace App\Services;

use App\Contracts\DeviceRepositoryInterface;
use App\Contracts\LogsRepositoryInterface;
use App\Models\AttendanceInformation;
use App\Models\DeviceLogs;
use App\Models\Devices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use TADPHP\TAD;
use TADPHP\TADFactory;

class DeviceService
{
    protected DeviceRepositoryInterface $deviceRepository;
    protected LogsRepositoryInterface $logsRepository;
    protected DeviceCommandService $commandService;

    public function __construct(
        DeviceRepositoryInterface $deviceRepository,
        ?LogsRepositoryInterface $logsRepository = null,
        ?DeviceCommandService $commandService = null
    ) {
        $this->deviceRepository = $deviceRepository;
        $this->logsRepository = $logsRepository ?? app(LogsRepositoryInterface::class);
        $this->commandService = $commandService ?? app(DeviceCommandService::class);
    }

    private function checkDeviceConnection(array $device)
    {
     try {
            $options = [
                'ip' => (string)$device['ip_address'],
                'com_key' => (int)$device['com_key'],
                'description' => 'TAD1',
                'soap_port' => (int)$device['soap_port'],
                'udp_port' => (int)$device['udp_port'],
                'encoding' => 'utf-8'
            ];
            $tad_factory = new TADFactory($options);
            $tad = $tad_factory->get_instance();
            if ($tad->is_alive()) {
                return $tad;
            }
        } catch (\Throwable $th) {
          return null;
        }
    }

    /**
     * Get all devices with connection status
     */
    public function getAllDevicesWithStatus(): array
    {
        return $this->deviceRepository->getAllWithStatus();
    }

    /**
     * Get all Online devices
     */
    public function getOnlineDevices(): array
    {
        $devices = $this->deviceRepository->getAll()->toArray();
        $onlineDevices = [];
        foreach ($devices as $device) {
            // if($device['is_active'] != 1 || $device['is_registration'] != 0 || $device['for_attendance'] != 0) {
            //     continue;
            // }
                if($device['is_active'] != 1 || $device['is_registration'] != 1) {
                continue;
            }
            $tad = $this->checkDeviceConnection($device);
            if ($tad) {
                $onlineDevices[] = [
                    'device' => $device,
                    'device_instance' => $tad,
                ];
            }
        }
        return $onlineDevices;
    }

    /**
     * Check device status
     */
    // public function checkDeviceStatus(string $serialNumber): array
    // {
    //     $device = $this->deviceRepository->findBySn($serialNumber);
    //     if (!$device) {
    //         throw new Exception('Device not found');
    //     }
    //     $response = $this->sendDeviceCommand($device->id, 'status');
    //     return [
    //         'device_id' => $device->device_id,
    //         'status' => $response['status'] ?? 'unknown',
    //         'last_seen' => $device->updated_at,
    //         'ip_address' => $device->ip_address ?? null,
    //     ];
    // }

    /**
     * Turn off device
     */
    public function turnOffDevice(int $deviceId)
    {
        return DB::transaction(function () use ($deviceId) {
            $device = $this->deviceRepository->findById($deviceId);

            if (!$device) {
                throw new Exception('Device not found');
            }
      
            try {
               $this->sendDeviceCommand($device->id, 'power_off');
            } catch (\Exception $e) {
                throw new Exception('Failed to turn off device');
            }
        });
    }

    /**
     * Test connection to a biometric device using TCP socket probe and TAD/Push verification.
     * Measures latency in milliseconds and returns connection diagnostics.
     *
     * @param Devices|int $device
     * @return array
     */
    public function testDeviceConnection(Devices|int $device): array
    {
        if (is_int($device)) {
            $deviceModel = $this->deviceRepository->findById($device);
            if (!$deviceModel) {
                throw new Exception("Device with ID {$device} not found");
            }
            $device = $deviceModel;
        }

        $ip = trim((string)$device->ip_address);
        $soapPort = (int)($device->soap_port ?: 80);
        $udpPort = (int)($device->udp_port ?: 4370);
        $sn = trim((string)$device->serial_number);

        $socketOnline = false;
        $latencyMs = null;
        $responsivePort = null;

        // 1. Probe TCP socket on SOAP Port (port 80)
        if (!empty($ip)) {
            $errNo = 0;
            $errStr = '';
            $probeStart = microtime(true);
            $fp = @fsockopen($ip, $soapPort, $errNo, $errStr, 1.5);
            if (is_resource($fp)) {
                $socketOnline = true;
                $responsivePort = $soapPort;
                $latencyMs = max(1, (int)round((microtime(true) - $probeStart) * 1000));
                fclose($fp);
            } else {
                // If SOAP port closed or blocked, try UDP port (4370)
                $probeStartUdp = microtime(true);
                $fpUdp = @fsockopen($ip, $udpPort, $errNo, $errStr, 1.2);
                if (is_resource($fpUdp)) {
                    $socketOnline = true;
                    $responsivePort = $udpPort;
                    $latencyMs = max(1, (int)round((microtime(true) - $probeStartUdp) * 1000));
                    fclose($fpUdp);
                }
            }
        }

        // 2. Check Push check-in status (seen within last 2 minutes)
        $isPushActive = $device->isOnline();

        // 3. Check TAD alive if socket is open
        $isTadAlive = false;
        if ($socketOnline) {
            try {
                $tad = $this->checkDeviceConnection($device->toArray());
                if ($tad && $tad->is_alive()) {
                    $isTadAlive = true;
                }
            } catch (\Throwable $th) {
                // Socket was responsive even if SOAP request threw
            }
        }

        $isOnline = $socketOnline || $isPushActive || $isTadAlive;

        // If responsive, update last_seen_at
        if ($isOnline) {
            $device->update(['last_seen_at' => now()]);
        }

        $protocols = [];
        if ($isTadAlive || $socketOnline) $protocols[] = "SOAP/TCP (:{$responsivePort})";
        if ($isPushActive) $protocols[] = "ADMS Push";
        $protocolStr = !empty($protocols) ? implode(' + ', $protocols) : 'Unreachable';

        return [
            'device_id' => $device->id,
            'device_name' => $device->device_name,
            'ip_address' => $ip,
            'serial_number' => $sn,
            'status' => $isOnline ? 'online' : 'offline',
            'is_online' => $isOnline,
            'latency_ms' => $latencyMs,
            'port' => $responsivePort,
            'protocol' => $protocolStr,
            'last_seen_at' => $device->last_seen_at ? $device->last_seen_at->toDateTimeString() : null,
            'last_seen_human' => $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'Never',
            'message' => $isOnline
                ? "Device is online and responding ({$latencyMs}ms via {$protocolStr})"
                : "Device is unreachable at {$ip}:{$soapPort} (connection timed out)",
        ];
    }

    /**
     * Sync device time with server (Dual support: Direct SOAP + ADMS Push command queue)
     */
    public function syncDeviceTime(int $deviceId): array
    {
        $device = $this->deviceRepository->findById($deviceId);
        if (!$device) {
            throw new Exception('Device not found');
        }

        $now = now();
        $dateStr = $now->format('Y-m-d');
        $timeStr = $now->format('H:i:s');
        $dtStr = $now->format('Y-m-d H:i:s');

        $soapSuccess = false;
        $pushQueued = false;
        $errors = [];

        // 1. Direct TAD SOAP sync
        try {
            $tad = $this->checkDeviceConnection($device->toArray());
            if ($tad && $tad->is_alive()) {
                $tad->set_date(['date' => $dateStr, 'time' => $timeStr]);
                $soapSuccess = true;
                $device->update(['last_seen_at' => now()]);
            }
        } catch (\Throwable $e) {
            $errors[] = "SOAP: " . $e->getMessage();
        }

        // 2. Dual support: Queue ADMS push command if device has serial number
        $sn = trim((string)$device->serial_number);
        if (!empty($sn) && $sn !== 'Fail!') {
            try {
                $this->commandService->queueCommand($sn, "SET OPTIONS DateTime={$dtStr}");
                $pushQueued = true;
            } catch (\Throwable $e) {
                $errors[] = "Push: " . $e->getMessage();
            }
        }

        if (!$soapSuccess && !$pushQueued) {
            throw new Exception("Failed to sync device time: " . implode(', ', $errors));
        }

        $channel = $soapSuccess && $pushQueued ? 'SOAP & Queued to Push' : ($soapSuccess ? 'SOAP' : 'Queued to ADMS Push');

        return [
            'success' => true,
            'device_id' => $device->id,
            'device_name' => $device->device_name,
            'synced_at' => $dtStr,
            'channel' => $channel,
            'message' => "Device time synchronized successfully via {$channel} ({$dtStr})",
        ];
    }

    /**
     * Get device info
     */
    public function getDeviceInfo(int $deviceId): array
    {
        $device = $this->deviceRepository->findById($deviceId);

        if (!$device) {
            throw new Exception('Device not found');
        }

        return [
            'id' => $device->id,
            'device_id' => $device->device_id,
            'name' => $device->device_name ?? null,
            'device_name' => $device->device_name ?? null,
            'serial_number' => $device->serial_number ?? null,
            'mac_address' => $device->mac_address ?? null,
            'status' => $device->isOnline() ? 'online' : 'offline',
            'ip_address' => $device->ip_address ?? null,
            'soap_port' => $device->soap_port ?? 80,
            'udp_port' => $device->udp_port ?? 4370,
            'com_key' => $device->com_key ?? 0,
            'is_registration' => $device->is_registration,
            'for_attendance' => $device->for_attendance,
            'is_active' => $device->is_active,
            'last_seen_at' => $device->last_seen_at ? $device->last_seen_at->toDateTimeString() : null,
            'last_cleared_at' => $device->last_cleared_at ? $device->last_cleared_at->toDateTimeString() : null,
            'created_at' => $device->created_at,
            'updated_at' => $device->updated_at,
        ];
    }

    /**
     * Restart device (Dual support: Direct SOAP + ADMS Push command queue)
     */
    public function restartDevice(int $deviceId): array
    {
        $device = $this->deviceRepository->findById($deviceId);
        if (!$device) {
            throw new Exception('Device not found');
        }

        $soapSuccess = false;
        $pushQueued = false;
        $errors = [];

        // 1. Direct TAD SOAP restart
        try {
            $tad = $this->checkDeviceConnection($device->toArray());
            if ($tad && $tad->is_alive()) {
                $tad->restart();
                $soapSuccess = true;
                $device->update(['last_seen_at' => now()]);
            }
        } catch (\Throwable $e) {
            $errors[] = "SOAP: " . $e->getMessage();
        }

        // 2. Dual support: Queue ADMS push command if device has serial number
        $sn = trim((string)$device->serial_number);
        if (!empty($sn) && $sn !== 'Fail!') {
            try {
                $this->commandService->queueCommand($sn, "REBOOT");
                $pushQueued = true;
            } catch (\Throwable $e) {
                $errors[] = "Push: " . $e->getMessage();
            }
        }

        if (!$soapSuccess && !$pushQueued) {
            throw new Exception("Failed to restart device: " . implode(', ', $errors));
        }

        $channel = $soapSuccess && $pushQueued ? 'SOAP & Queued to Push' : ($soapSuccess ? 'SOAP' : 'Queued to ADMS Push');

        return [
            'success' => true,
            'device_id' => $device->id,
            'device_name' => $device->device_name,
            'channel' => $channel,
            'message' => "Device restart command executed via {$channel}",
        ];
    }

   
    /**
     * Send command to device via API
     * Replace with actual device API integration
     */
    protected function sendDeviceCommand(int $deviceId, string $command, array $data = []): array
    {
        $devices = $this->deviceRepository->getAll();
       
        $device = $devices->find($deviceId);
        if (!$device) {
            throw new Exception('Device not found');
        }
        $tad = $this->checkDeviceConnection($device->toArray());
        if(!$tad) { throw new Exception('Device is offline');}
        
    
        switch ($command) {
            case 'restart':
              $tad->restart();
                break;
            case 'sync_time':
              $tad->set_date(['date' => $data['date'], 'time' => $data['time']]);
                break;
            case 'power_off':
              $tad->poweroff();
                break;
            
            default:
                // Handle other commands
                break;
        }

        // Mock response for demonstration
        return [
            'success' => true,
            'status' => 'online',
            'message' => 'Command executed successfully',
        ];
    }

    /**
     * Manually pull attendance logs from a specific biometric device via TAD SOAP.
     *
     * @param Devices|int $device Device instance or Device ID
     * @param array $options Optional filters: 'date', 'start_date', 'end_date', 'pin', 'biometric_id'
     * @return array
     */
    public function pullLogsFromDevice(Devices|int $device, array $options = []): array
    {
        if (is_int($device)) {
            $deviceModel = $this->deviceRepository->findById($device);
            if (!$deviceModel) {
                throw new Exception("Device with ID {$device} not found");
            }
            $device = $deviceModel;
        }

        // Check if device is reachable on network
        if (!TAD::is_device_online($device->ip_address, 2)) {
            return [
                'device_id' => $device->id,
                'device_name' => $device->device_name,
                'ip_address' => $device->ip_address,
                'status' => 'offline',
                'message' => "Device is offline or unreachable at {$device->ip_address}",
                'total_pulled' => 0,
                'filtered_count' => 0,
                'new_saved' => 0,
                'duplicates_skipped' => 0,
                'sample' => [],
            ];
        }

        try {
            $tadOptions = [
                'ip' => (string)$device->ip_address,
                'com_key' => (int)$device->com_key,
                'description' => (string)$device->device_name,
                'soap_port' => (int)($device->soap_port ?: 80),
                'udp_port' => (int)($device->udp_port ?: 4370),
                'encoding' => 'utf-8',
                'connection_timeout' => 5,
            ];
            $tad = (new TADFactory($tadOptions))->get_instance();

            $queryArgs = [];
            $pinFilter = $options['pin'] ?? $options['biometric_id'] ?? null;
            if (!empty($pinFilter)) {
                $queryArgs['pin'] = $pinFilter;
            }

            $response = $tad->get_att_log($queryArgs);
            $parsed = $response->to_array();

            $rawRows = [];
            if (isset($parsed['Row'])) {
                if (isset($parsed['Row']['PIN'])) {
                    $rawRows = [$parsed['Row']];
                } elseif (isset($parsed['Row'][0])) {
                    $rawRows = $parsed['Row'];
                }
            }

            // Filter rows based on date / pin
            $filteredRows = [];
            foreach ($rawRows as $row) {
                $pin = isset($row['PIN']) ? trim((string)$row['PIN']) : null;
                $rawDt = isset($row['DateTime']) ? trim((string)$row['DateTime']) : null;
                $status = isset($row['Status']) ? trim((string)$row['Status']) : '255';

                if (!$pin || !is_numeric($pin) || (int)$pin <= 0) {
                    continue;
                }
                if (!$rawDt || !strtotime($rawDt)) {
                    continue;
                }

                $carbonDt = \Carbon\Carbon::parse($rawDt);
                $dtrDate = $carbonDt->format('Y-m-d');
                $dtrTime = $carbonDt->format('H:i:s');
                $formattedDt = $carbonDt->format('Y-m-d H:i:s');

                if (!empty($options['date']) && $dtrDate !== $options['date']) {
                    continue;
                }
                if (!empty($options['start_date']) && $dtrDate < $options['start_date']) {
                    continue;
                }
                if (!empty($options['end_date']) && $dtrDate > $options['end_date']) {
                    continue;
                }
                if (!empty($pinFilter) && (string)$pin !== (string)$pinFilter) {
                    continue;
                }

                $filteredRows[] = [
                    'pin' => (int)$pin,
                    'datetime' => $formattedDt,
                    'date' => $dtrDate,
                    'time' => $dtrTime,
                    'status' => $status,
                ];
            }

            // Pre-fetch existing entries for fast O(1) deduplication
            $dates = array_unique(array_column($filteredRows, 'date'));
            $pins = array_unique(array_column($filteredRows, 'pin'));
            $existingKeys = [];

            if (!empty($dates) && !empty($pins)) {
                // Check DeviceLogs
                $existingLogs = DeviceLogs::whereIn('dtr_date', $dates)
                    ->whereIn('biometric_id', $pins)
                    ->select(['biometric_id', 'date_time'])
                    ->get();
                foreach ($existingLogs as $el) {
                    $existingKeys[$el->biometric_id . '|' . $el->date_time] = true;
                }

                // Check AttendanceInformation
                $minDt = min(array_column($filteredRows, 'datetime'));
                $maxDt = max(array_column($filteredRows, 'datetime'));
                $existingAtt = AttendanceInformation::whereBetween('first_entry', [$minDt, $maxDt])
                    ->whereIn('biometric_id', $pins)
                    ->select(['biometric_id', 'first_entry'])
                    ->get();
                foreach ($existingAtt as $ea) {
                    $existingKeys[$ea->biometric_id . '|' . $ea->first_entry] = true;
                }
            }

            $newSavedCount = 0;
            $duplicatesSkipped = 0;
            $sampleSaved = [];

            foreach ($filteredRows as $item) {
                $dedupKey = $item['pin'] . '|' . $item['datetime'];

                if (isset($existingKeys[$dedupKey])) {
                    $duplicatesSkipped++;
                    continue;
                }

                // Check logExists in LogsRepository (scans recent device_logs*.log)
                if ($this->logsRepository->logExists($item['pin'], $item['datetime'])) {
                    $duplicatesSkipped++;
                    $existingKeys[$dedupKey] = true;
                    continue;
                }

                $existingKeys[$dedupKey] = true;

                $logData = [
                    'biometric_id' => $item['pin'],
                    'dtr_date' => $item['date'],
                    'dtr_time' => $item['time'],
                    'dtr_type' => $item['status'],
                    'ip_address' => $device->ip_address,
                ];
                $rawLine = "{$item['pin']}\t{$item['datetime']}\t{$item['status']}";

                if ($device->is_registration == 1) {
                    // Registration devices don't store attendance records
                    $duplicatesSkipped++;
                    continue;
                }

                if ($device->for_attendance == 1) {
                    $saved = $this->logsRepository->saveForAttendance($logData);
                    if (!$saved) {
                        // Fallback to DeviceLogs if attendance save didn't match an active attendance event
                        $this->logsRepository->createLog($logData);
                    }
                } else {
                    $this->logsRepository->createLog($logData);
                }

                // Write to text file and structured log
                $this->logsRepository->writeToFile($logData);
                $this->logsRepository->writeStructuredLog($logData, $rawLine);

                $newSavedCount++;
                if (count($sampleSaved) < 20) {
                    $sampleSaved[] = [
                        'biometric_id' => $item['pin'],
                        'date_time' => $item['datetime'],
                        'status' => $item['status'],
                    ];
                }
            }

            // Update device connection status
            $this->deviceRepository->markAsConnected($device->ip_address);

            return [
                'device_id' => $device->id,
                'device_name' => $device->device_name,
                'ip_address' => $device->ip_address,
                'status' => 'success',
                'total_pulled' => count($rawRows),
                'filtered_count' => count($filteredRows),
                'new_saved' => $newSavedCount,
                'duplicates_skipped' => $duplicatesSkipped,
                'sample' => $sampleSaved,
            ];
        } catch (\Throwable $e) {
            Log::channel('device_logs')->error("pullLogsFromDevice failed on [{$device->id}] {$device->device_name}: " . $e->getMessage());
            return [
                'device_id' => $device->id,
                'device_name' => $device->device_name,
                'ip_address' => $device->ip_address,
                'status' => 'error',
                'message' => $e->getMessage(),
                'total_pulled' => 0,
                'filtered_count' => 0,
                'new_saved' => 0,
                'duplicates_skipped' => 0,
                'sample' => [],
            ];
        }
    }

    /**
     * Manually pull attendance logs from all active devices based on Devices model.
     *
     * @param array $options Optional filters: 'date', 'start_date', 'end_date', 'pin', 'biometric_id'
     * @return array
     */
    public function pullLogsFromActiveDevices(array $options = []): array
    {
        $devices = Devices::active()->get();
        $results = [];
        $totalPulled = 0;
        $totalSaved = 0;
        $totalSkipped = 0;
        $onlineCount = 0;
        $offlineCount = 0;

        foreach ($devices as $device) {
            $res = $this->pullLogsFromDevice($device, $options);
            $results[] = $res;

            if ($res['status'] === 'success') {
                $onlineCount++;
                $totalPulled += $res['total_pulled'];
                $totalSaved += $res['new_saved'];
                $totalSkipped += $res['duplicates_skipped'];
            } else {
                $offlineCount++;
            }
        }

        return [
            'total_devices' => $devices->count(),
            'online_devices' => $onlineCount,
            'offline_devices' => $offlineCount,
            'total_pulled' => $totalPulled,
            'total_saved' => $totalSaved,
            'total_skipped' => $totalSkipped,
            'devices' => $results,
        ];
    }

    /**
     * Request a biometric device to resend attendance logs via HTTP Push (ADMS) command.
     * Enqueues a DATA QUERY ATTLOG (or LOG) command to be fetched on the device's next /iclock/getrequest poll.
     *
     * @param Devices|int $device
     * @param array $options Filters: 'date', 'start_date', 'end_date', 'type' ('DATA QUERY ATTLOG'|'LOG'|'BOTH'|'CHECK')
     * @return array
     */
    public function requestLogResend(Devices|int $device, array $options = []): array
    {
        if (is_int($device)) {
            $deviceModel = $this->deviceRepository->findById($device);
            if (!$deviceModel) {
                throw new Exception("Device with ID {$device} not found");
            }
            $device = $deviceModel;
        }

        $sn = trim((string)$device->serial_number);
        if (empty($sn) || $sn === 'Fail!') {
            return [
                'device_id' => $device->id,
                'device_name' => $device->device_name,
                'serial_number' => $device->serial_number,
                'status' => 'error',
                'message' => 'Device does not have a valid serial number for ADMS push commands',
                'commands_queued' => [],
            ];
        }

        // Format date/time range
        $startDate = $options['start_date'] ?? $options['date'] ?? now()->format('Y-m-d');
        $endDate = $options['end_date'] ?? $options['date'] ?? now()->format('Y-m-d');

        $startTime = str_contains($startDate, ' ') ? $startDate : "{$startDate} 00:00:00";
        $endTime = str_contains($endDate, ' ') ? $endDate : "{$endDate} 23:59:59";

        $type = strtoupper($options['type'] ?? 'DATA QUERY ATTLOG');
        $commandsToQueue = [];

        switch ($type) {
            case 'LOG':
                $commandsToQueue[] = "LOG\tStartTime={$startTime}\tEndTime={$endTime}";
                break;
            case 'CHECK':
                $commandsToQueue[] = "CHECK";
                break;
            case 'BOTH':
                $commandsToQueue[] = "DATA QUERY ATTLOG\tStartTime={$startTime}\tEndTime={$endTime}";
                $commandsToQueue[] = "LOG\tStartTime={$startTime}\tEndTime={$endTime}";
                break;
            case 'DATA QUERY ATTLOG':
            default:
                $commandsToQueue[] = "DATA QUERY ATTLOG\tStartTime={$startTime}\tEndTime={$endTime}";
                break;
        }

        $queuedRecords = [];
        foreach ($commandsToQueue as $cmd) {
            $record = $this->commandService->queueCommand($sn, $cmd);
            $queuedRecords[] = $record;
        }

        Log::channel('device_logs')->info("Queued attendance resend command for device [{$device->id}] {$device->device_name} (SN: {$sn})", [
            'commands' => $commandsToQueue,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        return [
            'device_id' => $device->id,
            'device_name' => $device->device_name,
            'serial_number' => $sn,
            'ip_address' => $device->ip_address,
            'status' => 'queued',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'commands' => $queuedRecords,
            'message' => 'Command successfully queued. The device will receive it on its next /iclock/getrequest poll and push matching logs to /iclock/cdata.',
        ];
    }

    /**
     * Request all active biometric devices to resend attendance logs via ADMS push command.
     *
     * @param array $options
     * @return array
     */
    public function requestLogResendFromActiveDevices(array $options = []): array
    {
        $devices = Devices::active()->get();
        $results = [];
        $queuedCount = 0;
        $skippedCount = 0;

        foreach ($devices as $device) {
            $res = $this->requestLogResend($device, $options);
            $results[] = $res;

            if ($res['status'] === 'queued') {
                $queuedCount++;
            } else {
                $skippedCount++;
            }
        }

        return [
            'total_devices' => $devices->count(),
            'devices_queued' => $queuedCount,
            'devices_skipped' => $skippedCount,
            'devices' => $results,
        ];
    }

    /**
     * Clear attendance logs from a specific biometric device.
     * Safely executes pre-wipe log pull & sync verification before issuing CLEAR LOG or SOAP ClearData(1).
     *
     * @param Devices|int $device Device model instance or Device ID
     * @param array $options Options:
     *      - 'method': 'both' (default), 'soap', or 'adms'
     *      - 'force': bool (default false, bypasses some safety confirmations)
     *      - 'dry_run': bool (default false, simulates verification without sending clear command)
     *      - 'skip_sync': bool (default false, skips pre-wipe pull, requires force: true)
     * @return array Result array with status, device details, and sync statistics
     */
    public function clearAttendanceLogsFromDevice(Devices|int $device, array $options = []): array
    {
        if (is_int($device)) {
            $deviceModel = $this->deviceRepository->findById($device);
            if (!$deviceModel) {
                throw new Exception("Device with ID {$device} not found");
            }
            $device = $deviceModel;
        }

        $method = strtolower($options['method'] ?? 'both');
        $force = (bool)($options['force'] ?? false);
        $dryRun = (bool)($options['dry_run'] ?? false);
        $skipSync = (bool)($options['skip_sync'] ?? false);

        // 1. Connectivity Check
        $isOnline = $device->isOnline() || ($method !== 'adms' && !app()->runningUnitTests() && TAD::is_device_online($device->ip_address, 2));
        if (!$isOnline && !$force) {
            Log::channel('device_logs')->warning("Device attendance logs clear skipped: Device [{$device->id}] {$device->device_name} is OFFLINE.");
            return [
                'device_id' => $device->id,
                'device_name' => $device->device_name,
                'serial_number' => $device->serial_number,
                'ip_address' => $device->ip_address,
                'status' => 'skipped_offline',
                'method' => $method,
                'is_online' => false,
                'pre_sync' => null,
                'message' => "Device is offline or unreachable at {$device->ip_address}. Clear operation safely deferred to prevent potential data loss.",
            ];
        }

        // 2. Pre-Wipe Log Pull & Synchronization Verification Gate
        $preSyncStats = null;
        if (!$skipSync || !$force) {
            $pullResult = $this->pullLogsFromDevice($device);
            $preSyncStats = [
                'total_pulled' => $pullResult['total_pulled'] ?? 0,
                'new_saved' => $pullResult['new_saved'] ?? 0,
                'duplicates_skipped' => $pullResult['duplicates_skipped'] ?? 0,
                'pull_status' => $pullResult['status'] ?? 'unknown',
            ];

            // If the pull itself failed and we are not forced, abort to prevent wiping un-pulled records
            if (($pullResult['status'] ?? '') === 'error' && !$force) {
                Log::channel('device_logs')->error("Device attendance logs clear aborted: Pre-sync pull failed on [{$device->id}] {$device->device_name}: " . ($pullResult['message'] ?? 'Unknown error'));
                return [
                    'device_id' => $device->id,
                    'device_name' => $device->device_name,
                    'serial_number' => $device->serial_number,
                    'ip_address' => $device->ip_address,
                    'status' => 'aborted_sync_error',
                    'method' => $method,
                    'is_online' => $isOnline,
                    'pre_sync' => $preSyncStats,
                    'message' => "Pre-wipe synchronization failed: {$pullResult['message']}. Deletion aborted to safeguard data.",
                ];
            }
        }

        // 3. Dry-Run Check
        if ($dryRun) {
            Log::channel('device_logs')->info("Device attendance logs clear DRY-RUN completed for [{$device->id}] {$device->device_name}.");
            return [
                'device_id' => $device->id,
                'device_name' => $device->device_name,
                'serial_number' => $device->serial_number,
                'ip_address' => $device->ip_address,
                'status' => 'dry_run_success',
                'method' => $method,
                'is_online' => $isOnline,
                'pre_sync' => $preSyncStats,
                'message' => "Dry-run succeeded: Device is reachable, logs synchronized, and ready for clearance. No data was deleted.",
            ];
        }

        // 4. Execution via Direct SOAP or ADMS Push
        $executedMethod = null;
        $executionSuccess = false;
        $queuedCommand = null;

        // Try SOAP first if method is 'soap' or 'both'
        if (in_array($method, ['soap', 'both']) && TAD::is_device_online($device->ip_address, 2)) {
            try {
                $tadOptions = [
                    'ip' => (string)$device->ip_address,
                    'com_key' => (int)$device->com_key,
                    'description' => (string)$device->device_name,
                    'soap_port' => (int)($device->soap_port ?: 80),
                    'udp_port' => (int)($device->udp_port ?: 4370),
                    'encoding' => 'utf-8',
                    'connection_timeout' => 5,
                ];
                $tad = (new TADFactory($tadOptions))->get_instance();

                // Value 1 is strictly for clearing attendance logs (ATTLOG / GLog)
                $soapResponse = $tad->delete_data(['value' => 1]);
                $responseStr = (string)$soapResponse;

                if (!str_contains($responseStr, 'Fail!')) {
                    $executedMethod = 'soap';
                    $executionSuccess = true;
                }
            } catch (\Throwable $soapEx) {
                Log::channel('device_logs')->warning("SOAP delete_data(value=1) failed on [{$device->id}] {$device->device_name}: " . $soapEx->getMessage());
            }
        }

        // Fallback to ADMS Push if SOAP didn't execute and method is 'adms' or 'both'
        if (!$executionSuccess && in_array($method, ['adms', 'both'])) {
            $sn = trim((string)$device->serial_number);
            if (!empty($sn) && $sn !== 'Fail!') {
                $queuedCommand = $this->commandService->queueCommand($sn, 'CLEAR LOG');
                $executedMethod = 'adms';
                $executionSuccess = !empty($queuedCommand);
            }
        }

        if ($executionSuccess) {
            $device->update(['last_cleared_at' => now()]);

            Log::channel('device_logs')->info("Successfully cleared/queued attendance logs for device [{$device->id}] {$device->device_name}", [
                'method' => $executedMethod,
                'pre_sync' => $preSyncStats,
                'queued_command' => $queuedCommand,
            ]);

            return [
                'device_id' => $device->id,
                'device_name' => $device->device_name,
                'serial_number' => $device->serial_number,
                'ip_address' => $device->ip_address,
                'status' => $executedMethod === 'soap' ? 'success' : 'queued',
                'method' => $executedMethod,
                'is_online' => $isOnline,
                'pre_sync' => $preSyncStats,
                'queued_command' => $queuedCommand,
                'last_cleared_at' => now()->toDateTimeString(),
                'message' => $executedMethod === 'soap'
                    ? 'Device attendance logs cleared immediately via direct SOAP.'
                    : 'CLEAR LOG command queued. Device will clear attendance logs on its next poll.',
            ];
        }

        return [
            'device_id' => $device->id,
            'device_name' => $device->device_name,
            'serial_number' => $device->serial_number,
            'ip_address' => $device->ip_address,
            'status' => 'error',
            'method' => $method,
            'is_online' => $isOnline,
            'pre_sync' => $preSyncStats,
            'message' => 'Failed to clear device logs via requested method(s). Device may be unreachable or lacking serial number.',
        ];
    }

    /**
     * Clear attendance logs across all active devices.
     *
     * @param array $options Options:
     *      - 'method': 'both' (default), 'soap', or 'adms'
     *      - 'catch_up': bool (only target devices where needsLogClear is true)
     *      - 'older_than': int (days since last clear, default 7)
     *      - 'force': bool
     *      - 'dry_run': bool
     *      - 'skip_sync': bool
     * @return array
     */
    public function clearAttendanceLogsFromActiveDevices(array $options = []): array
    {
        $query = Devices::active();
        if (!empty($options['catch_up'])) {
            $days = (int)($options['older_than'] ?? 7);
            $query = Devices::needingLogClear($days);
        }

        $devices = $query->get();
        $results = [];
        $clearedCount = 0;
        $queuedCount = 0;
        $skippedOfflineCount = 0;
        $abortedCount = 0;
        $errorCount = 0;

        foreach ($devices as $device) {
            $res = $this->clearAttendanceLogsFromDevice($device, $options);
            $results[] = $res;

            switch ($res['status'] ?? '') {
                case 'success':
                case 'dry_run_success':
                    $clearedCount++;
                    break;
                case 'queued':
                    $queuedCount++;
                    break;
                case 'skipped_offline':
                    $skippedOfflineCount++;
                    break;
                case 'aborted_sync_error':
                    $abortedCount++;
                    break;
                default:
                    $errorCount++;
                    break;
            }
        }

        return [
            'total_targeted' => $devices->count(),
            'cleared_count' => $clearedCount,
            'queued_count' => $queuedCount,
            'skipped_offline_count' => $skippedOfflineCount,
            'aborted_sync_count' => $abortedCount,
            'error_count' => $errorCount,
            'devices' => $results,
        ];
    }
}
