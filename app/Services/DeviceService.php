<?php

namespace App\Services;

use App\Contracts\DeviceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
}
