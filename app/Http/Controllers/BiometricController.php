<?php

namespace App\Http\Controllers;

use App\Services\BiometricService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BiometricController extends Controller
{
    protected BiometricService $biometricService;

    public function __construct(BiometricService $biometricService)
    {
        $this->biometricService = $biometricService;
    }

    /**
     * Sync time logs from biometric device
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string',
            'date_from' => 'sometimes|date|date_format:Y-m-d',
            'date_to' => 'sometimes|date|date_format:Y-m-d',
        ]);

        try {
            $result = $this->biometricService->syncTimeLogs(
                $validated['device_id'],
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null
            );

            return response()->json($result, 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get time logs with filters
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'user_id' => $request->get('user_id'),
            'device_id' => $request->get('device_id'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'log_type' => $request->get('log_type'),
            'per_page' => $request->get('per_page', 50),
        ];

        try {
            $logs = $this->biometricService->getTimeLogs($filters);

            return response()->json([
                'success' => true,
                'data' => $logs
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
