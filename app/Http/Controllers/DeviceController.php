<?php

namespace App\Http\Controllers;

use App\Services\DeviceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    protected DeviceService $deviceService;

    public function __construct(DeviceService $deviceService)
    {
        $this->deviceService = $deviceService;
    }

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
     * Get device status
     */
    public function status(int $id): JsonResponse
    {
        try {
            $status = $this->deviceService->checkDeviceStatus($id);

            return response()->json([
                'success' => true,
                'data' => $status
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Turn on device
     */
    public function powerOn(int $id): JsonResponse
    {
        try {
            $this->deviceService->turnOnDevice($id);

            return response()->json([
                'success' => true,
                'message' => 'Device turned on successfully'
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
}
