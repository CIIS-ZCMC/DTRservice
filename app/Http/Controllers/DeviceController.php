<?php

namespace App\Http\Controllers;

use App\Contracts\LogsRepositoryInterface;
use App\Services\DeviceService;
use App\Services\LogsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DeviceController extends Controller
{
    
    public function __construct( 
        protected DeviceService $deviceService,
        protected LogsService $logsService
        ){}

    /**
     * Get all devices
     */
    public function index(): JsonResponse
    {
        try {
            $devices = $this->deviceService->getOnlineDevices();
            return response()->json([
                'success' => true,
                'data' => $devices
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all devices with connection status
     */
    public function getAllWithStatus(): JsonResponse
    {
        try {
            $devices = $this->deviceService->getAllDevicesWithStatus();
            return response()->json([
                'success' => true,
                'data' => $devices
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Turn off device
     */
    public function powerOff(int $id): JsonResponse
    {
        try {
            $this->deviceService->turnOffDevice($id);

            return response()->json([
                'success' => true,
                'message' => 'Device turned off successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync device time
     */
    public function syncTime(int $id): JsonResponse
    {
        try {
            $this->deviceService->syncDeviceTime($id);

            return response()->json([
                'success' => true,
                'message' => 'Device time synced successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get device info
     */
    public function show(int $id): JsonResponse
    {
        try {
            $info = $this->deviceService->getDeviceInfo($id);

            return response()->json([
                'success' => true,
                'data' => $info
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Restart device
     */
    public function restart(int $id): JsonResponse
    {
        try {
            $this->deviceService->restartDevice($id);

            return response()->json([
                'success' => true,
                'message' => 'Device restarted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle request of ZKTeco device | Biometric Device
     */
    public function handleDevicePush(Request $request)
    {
        try {
            return $this->logsService->storeLog($request);
        } catch (\Throwable $th) {
            Log::channel('device_logs')->error($th->getMessage());
        }
    }

    
}
