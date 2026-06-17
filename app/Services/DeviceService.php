<?php

namespace App\Services;

use App\Contracts\DeviceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Exception;
use TADPHP\TADFactory;

class DeviceService
{
    protected DeviceRepositoryInterface $deviceRepository;

    public function __construct(DeviceRepositoryInterface $deviceRepository)
    {
        $this->deviceRepository = $deviceRepository;
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
                $onlineDevices[] = $device;
            }
        }
        return $onlineDevices;
    }

    /**
     * Check device status
     */
    public function checkDeviceStatus(int $deviceId): array
    {
        $device = $this->deviceRepository->findById($deviceId);
        if (!$device) {
            throw new Exception('Device not found');
        }
       return $response = $this->sendDeviceCommand($device->id, 'status');
        return [
            'device_id' => $device->device_id,
            'status' => $response['status'] ?? 'unknown',
            'last_seen' => $device->updated_at,
            'ip_address' => $device->ip_address ?? null,
        ];
    }

    /**
     * Turn on device
     */
    public function turnOnDevice(int $deviceId): bool
    {
        return DB::transaction(function () use ($deviceId) {
            $device = $this->deviceRepository->findById($deviceId);

            if (!$device) {
                throw new Exception('Device not found');
            }

            // Send power on command to device
            $response = $this->sendDeviceCommand($device->device_id, 'power_on');

         

            throw new Exception('Failed to turn on device');
        });
    }

    /**
     * Turn off device
     */
    public function turnOffDevice(int $deviceId): bool
    {
        return DB::transaction(function () use ($deviceId) {
            $device = $this->deviceRepository->findById($deviceId);

            if (!$device) {
                throw new Exception('Device not found');
            }
            // Send power off command to device
            $response = $this->sendDeviceCommand($device->device_id, 'power_off');
            throw new Exception('Failed to turn off device');
        });
    }

    /**
     * Sync device time with server
     */
    public function syncDeviceTime(int $deviceId): bool
    {
        return DB::transaction(function () use ($deviceId) {
            $device = $this->deviceRepository->findById($deviceId);
            if (!$device) {
                throw new Exception('Device not found');
            }
            $serverTime = now()->format('Y-m-d H:i:s');
            // Send time sync command to device
            $response = $this->sendDeviceCommand($device->device_id, 'sync_time', [
                'time' => $serverTime,
            ]);
            throw new Exception('Failed to sync device time');
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

            // Send restart command to device
            $response = $this->sendDeviceCommand($device->device_id, 'restart');

        
            throw new Exception('Failed to restart device');
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
        dd($device);
        if (!$device) {
            throw new Exception('Device not found');
        }

        

        switch ($command) {
            case 'restart':
                // Handle restart command
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
}
