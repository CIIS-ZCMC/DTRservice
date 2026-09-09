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
     * Sync device time with server
     */
    public function syncDeviceTime(int $deviceId)
    {
        return DB::transaction(function () use ($deviceId) {
            $device = $this->deviceRepository->findById($deviceId);
            if (!$device) {
                throw new Exception('Device not found');
            }
           
            // Send time sync command to device
            try {
                $this->sendDeviceCommand($device->id, 'sync_time', [
                    'date' => now()->format('Y-m-d'),
                    'time' =>now()->format('H:i:s'),
                ]);
            } catch (\Exception $e) {
                throw new Exception('Failed to sync device time: ' . $e->getMessage());
            }
        });
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
            'name' => $device->name ?? null,
            'status' => $device->status ?? 'unknown',
            'ip_address' => $device->ip_address ?? null,
            'last_sync_at' => $device->last_sync_at ?? null,
            'created_at' => $device->created_at,
            'updated_at' => $device->updated_at,
        ];
    }

    /**
     * Restart device
     */
    public function restartDevice(int $deviceId): bool
    {
        return DB::transaction(function () use ($deviceId) {
            $device = $this->deviceRepository->findById($deviceId);

            if (!$device) {
                throw new Exception('Device not found');
            }
            try {
           $this->sendDeviceCommand($device->id, 'restart');
            } catch (\Exception $e) {
                throw new Exception('Failed to restart device: ' . $e->getMessage());
            }
        });
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
}
