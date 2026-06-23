<?php

namespace App\Http\Controllers;

use App\Services\TimeRecordService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TimeRecordController extends Controller
{
    public function __construct(
        protected TimeRecordService $timeRecordService
    ) {}

    /**
     * Get time records with date range filters
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'biometric_id' => 'required|integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $biometricId = $request->input('biometric_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if ($dateFrom && $dateTo) {
            $records = $this->timeRecordService->getEmployeeTimeRecordsRange($biometricId, $dateFrom, $dateTo);
        } else {
            $date = $dateFrom ?? now()->format('Y-m-d');
            $records = [$this->timeRecordService->getEmployeeTimeRecord($biometricId, $date)];
        }

        return response()->json([
            'success' => true,
            'data' => $records,
        ]);
    }

    /**
     * Get specific day's time record
     */
    public function show(int $biometricId, string $date): JsonResponse
    {
        $record = $this->timeRecordService->getEmployeeTimeRecord($biometricId, $date);

        return response()->json([
            'success' => true,
            'data' => $record,
        ]);
    }
    
    public function computeDTR(int $biometricId, string $date): JsonResponse
    {
        $record = $this->timeRecordService->consolidateDailyTimeRecord($biometricId, $date);
        return response()->json([
            'success' => true,
            'data' => $record,
        ]);
    }   
}
