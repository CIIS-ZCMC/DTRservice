<?php

namespace App\Http\Controllers;

use App\Models\Biometrics;
use App\Models\DeviceLogs;
use App\Models\DeviceLogsHrbliz;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class DeviceLogController extends Controller
{
    /**
     * Display a listing of device logs.
     *
     * Optional Query Parameters:
     * - hrbliz_biometric_id: Filter by HRBLIZ biometric ID (automatically resolved to canonical ID)
     * - biometric_id: Filter by standard biometric PIN/ID
     * - date: Exact DTR date (YYYY-MM-DD)
     * - date_from: Start date (YYYY-MM-DD)
     * - date_to: End date (YYYY-MM-DD)
     * - status: Filter by punch status (0, 1, etc.)
     * - device_name: Filter by device name
     * - per_page: Pagination size (default: 20, max: 200)
     * - source: 'standard' (default) or 'hrbliz'
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $source = strtolower($request->query('source', 'standard'));
        $useHrbliz = ($source === 'hrbliz') && Schema::hasTable('device_logs_hrbliz');

        $query = $useHrbliz ? DeviceLogsHrbliz::query() : DeviceLogs::query();

        if ($request->filled('hrbliz_biometric_id')) {
            $hrbPin = (int)$request->query('hrbliz_biometric_id');
            $canonicalBio = Schema::hasTable('biometrics')
                ? Biometrics::where('hrbliz_biometric_id', $hrbPin)->first()
                : null;
            $targetBioId = $canonicalBio?->biometric_id ?? $hrbPin;
            $query->where('biometric_id', (string)$targetBioId);
        } elseif ($request->filled('biometric_id')) {
            $query->where('biometric_id', (string)$request->query('biometric_id'));
        }

        if ($request->filled('date')) {
            $query->where('dtr_date', $request->query('date'));
        }

        if ($request->filled('date_from')) {
            $query->where('dtr_date', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('dtr_date', '<=', $request->query('date_to'));
        }

        if ($request->filled('status')) {
            $query->where('status', (int)$request->query('status'));
        }

        if ($request->filled('device_name')) {
            $query->where('device_name', 'like', '%' . $request->query('device_name') . '%');
        }

        $perPage = min((int)$request->query('per_page', 20), 200);
        if ($perPage <= 0) {
            $perPage = 20;
        }

        $logs = $query->orderBy('date_time', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'source' => $useHrbliz ? 'device_logs_hrbliz' : 'device_logs',
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ], 200);
    }

    /**
     * Retrieve a specific device log by ID.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $source = strtolower($request->query('source', 'standard'));
        $useHrbliz = ($source === 'hrbliz') && Schema::hasTable('device_logs_hrbliz');

        $log = $useHrbliz 
            ? DeviceLogsHrbliz::find($id) 
            : DeviceLogs::find($id);

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Device log not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $log,
        ], 200);
    }

    /**
     * Insert a new log or a batch of logs into device_logs.
     *
     * Supports both:
     * 1) Single log JSON object:
     *    {
     *      "hrbliz_biometric_id": "8765",      // HRBLIZ employee biometric ID (auto-mapped to canonical biometric_id)
     *      // OR "biometric_id": "1001",       // standard biometric_id by default
     *      "date_time": "2026-10-23 08:00:00",
     *      "status": 0,
     *      "name": "Juan Dela Cruz",           // optional (auto-resolved from Biometrics if omitted)
     *      "dtr_date": "2026-10-23",           // optional (auto-extracted from date_time if omitted)
     *      "device_name": "API_ENTRY",         // optional
     *      "is_Shifting": 0,                   // optional (default: 0)
     *      "schedule": null,                   // optional
     *      "active": 1                         // optional (default: 1)
     *    }
     *
     * 2) Bulk / Batch array of logs:
     *    {
     *      "logs": [ { ... }, { ... } ]
     *    }
     *    OR top-level array: [ { ... }, { ... } ]
     *
     * Optional Control Flags:
     * - prevent_duplicates: bool (default: true) -> Skips insert if biometric_id + date_time already exists.
     * - is_hrbliz: bool (default: false) -> Target device_logs_hrbliz instead of device_logs.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Determine payload structure (single item vs batch)
        $isBatch = false;
        $rawPayload = $request->all();

        if ($request->isJson() && is_array($request->json()->all()) && array_is_list($request->json()->all())) {
            $items = $request->json()->all();
            $isBatch = true;
        } elseif ($request->has('logs') && is_array($request->input('logs'))) {
            $items = $request->input('logs');
            $isBatch = true;
        } else {
            $items = [$rawPayload];
        }

        if (empty($items)) {
            return response()->json([
                'success' => false,
                'message' => 'No log data provided in request.',
            ], 422);
        }

        $preventDuplicates = filter_var(
            $request->input('prevent_duplicates', $request->query('prevent_duplicates', true)),
            FILTER_VALIDATE_BOOLEAN
        );

        $isHrbliz = filter_var(
            $request->input('is_hrbliz', $request->query('is_hrbliz', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        $targetModelClass = ($isHrbliz && Schema::hasTable('device_logs_hrbliz'))
            ? DeviceLogsHrbliz::class
            : DeviceLogs::class;

        // 2. Validate all records before saving
        $validatedRecords = [];
        $validationErrors = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $validationErrors["item.{$index}"] = ['Expected an object for log record.'];
                continue;
            }

            // Support hrbliz_biometric_id as primary input or fallback to biometric_id
            $hrblizPin = $item['hrbliz_biometric_id'] ?? null;
            $bioPin = $item['biometric_id'] ?? null;

            if ($hrblizPin === null && $bioPin === null) {
                $validationErrors["logs.{$index}.biometric_id"] = ['Either hrbliz_biometric_id or biometric_id is required.'];
                continue;
            }

            // Normalization of date/time fields if separated (e.g. dtr_date + dtr_time)
            $dateTimeStr = $item['date_time'] ?? null;
            if (!$dateTimeStr && !empty($item['dtr_date']) && !empty($item['dtr_time'])) {
                $dateTimeStr = trim($item['dtr_date']) . ' ' . trim($item['dtr_time']);
            } elseif (!$dateTimeStr && !empty($item['dtr_date'])) {
                $dateTimeStr = trim($item['dtr_date']) . ' ' . now()->format('H:i:s');
            } elseif (!$dateTimeStr) {
                $dateTimeStr = now()->format('Y-m-d H:i:s');
            }

            // Try to parse valid Carbon date_time
            try {
                $carbonDt = Carbon::parse($dateTimeStr);
                $formattedDateTime = $carbonDt->format('Y-m-d H:i:s');
                $formattedDtrDate = !empty($item['dtr_date'])
                    ? Carbon::parse($item['dtr_date'])->format('Y-m-d')
                    : $carbonDt->format('Y-m-d');
            } catch (\Throwable $e) {
                $validationErrors["logs.{$index}.date_time"] = ['Invalid date_time format. Expected YYYY-MM-DD HH:MM:SS.'];
                continue;
            }

            $item['date_time'] = $formattedDateTime;
            $item['dtr_date'] = $formattedDtrDate;

            $validator = Validator::make($item, [
                'name'         => 'nullable|string|max:191',
                'dtr_date'     => 'required|date_format:Y-m-d',
                'date_time'    => 'required|date_format:Y-m-d H:i:s',
                'status'       => 'nullable|integer',
                'is_Shifting'  => 'nullable|integer',
                'schedule'     => 'nullable|string',
                'active'       => 'nullable|integer',
                'device_name'  => 'nullable|string|max:200',
            ]);

            if ($validator->fails()) {
                $validationErrors["logs.{$index}"] = $validator->errors()->all();
            } else {
                $validatedRecords[] = [
                    'raw_hrbliz_id' => $hrblizPin !== null ? (string)$hrblizPin : null,
                    'raw_bio_id'    => $bioPin !== null ? (string)$bioPin : null,
                    'name'          => !empty($item['name']) ? trim($item['name']) : null,
                    'dtr_date'      => $formattedDtrDate,
                    'date_time'     => $formattedDateTime,
                    'status'        => isset($item['status']) ? (int)$item['status'] : 0,
                    'is_Shifting'   => isset($item['is_Shifting']) ? (int)$item['is_Shifting'] : 0,
                    'schedule'      => $item['schedule'] ?? null,
                    'active'        => isset($item['active']) ? (int)$item['active'] : 1,
                    'device_name'   => !empty($item['device_name']) ? trim($item['device_name']) : 'API',
                ];
            }
        }

        if (!empty($validationErrors)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed for one or more device log records.',
                'errors'  => $validationErrors,
            ], 422);
        }

        // 3. Resolve IDs and Employee Names from Biometrics
        // Collect all distinct IDs for batch lookup
        $hrblizIdsToLookup = collect($validatedRecords)
            ->filter(fn($rec) => !empty($rec['raw_hrbliz_id']))
            ->pluck('raw_hrbliz_id')
            ->unique()
            ->values()
            ->all();

        $stdIdsToLookup = collect($validatedRecords)
            ->filter(fn($rec) => empty($rec['raw_hrbliz_id']) && !empty($rec['raw_bio_id']))
            ->pluck('raw_bio_id')
            ->unique()
            ->values()
            ->all();

        $biometricsByHrbliz = [];
        $biometricsByStd = [];

        if (Schema::hasTable('biometrics')) {
            if (!empty($hrblizIdsToLookup)) {
                $biometricsByHrbliz = Biometrics::whereIn('hrbliz_biometric_id', $hrblizIdsToLookup)
                    ->get()
                    ->keyBy(fn($b) => (string)$b->hrbliz_biometric_id)
                    ->all();
            }
            if (!empty($stdIdsToLookup)) {
                $biometricsByStd = Biometrics::whereIn('biometric_id', $stdIdsToLookup)
                    ->get()
                    ->keyBy(fn($b) => (string)$b->biometric_id)
                    ->all();
            }
        }

        // 4. Perform insertion inside a database transaction
        $insertedLogs = [];
        $skippedDuplicates = 0;

        try {
            DB::transaction(function () use (
                &$validatedRecords,
                &$biometricsByHrbliz,
                &$biometricsByStd,
                $preventDuplicates,
                $targetModelClass,
                &$insertedLogs,
                &$skippedDuplicates
            ) {
                foreach ($validatedRecords as $rec) {
                    $canonicalBiometricId = null;
                    $employeeName = $rec['name'];
                    $hrblizIdEcho = $rec['raw_hrbliz_id'];

                    if (!empty($rec['raw_hrbliz_id'])) {
                        // Look up via hrbliz_biometric_id
                        $matched = $biometricsByHrbliz[$rec['raw_hrbliz_id']] ?? null;
                        if (!$matched && Schema::hasTable('biometrics')) {
                            // Fallback if hrbliz_biometric_id was stored under biometric_id
                            $matched = Biometrics::where('biometric_id', $rec['raw_hrbliz_id'])->first();
                        }

                        if ($matched) {
                            $canonicalBiometricId = (string)$matched->biometric_id;
                            if (empty($employeeName)) {
                                $employeeName = $matched->name;
                            }
                        } else {
                            $canonicalBiometricId = $rec['raw_hrbliz_id'];
                        }
                    } else {
                        // Standard biometric_id
                        $matched = $biometricsByStd[$rec['raw_bio_id']] ?? null;
                        if ($matched) {
                            $canonicalBiometricId = (string)$matched->biometric_id;
                            $hrblizIdEcho = $matched->hrbliz_biometric_id ? (string)$matched->hrbliz_biometric_id : null;
                            if (empty($employeeName)) {
                                $employeeName = $matched->name;
                            }
                        } else {
                            $canonicalBiometricId = $rec['raw_bio_id'];
                        }
                    }

                    if (empty($employeeName)) {
                        $employeeName = 'Unknown';
                    }

                    $insertData = [
                        'biometric_id' => $canonicalBiometricId,
                        'name'         => $employeeName,
                        'dtr_date'     => $rec['dtr_date'],
                        'date_time'    => $rec['date_time'],
                        'status'       => $rec['status'],
                        'is_Shifting'  => $rec['is_Shifting'],
                        'schedule'     => $rec['schedule'],
                        'active'       => $rec['active'],
                        'device_name'  => $rec['device_name'],
                    ];

                    // Duplicate check
                    if ($preventDuplicates) {
                        $exists = $targetModelClass::where('biometric_id', $canonicalBiometricId)
                            ->where('date_time', $rec['date_time'])
                            ->exists();

                        if ($exists) {
                            $skippedDuplicates++;
                            continue;
                        }
                    }

                    $created = $targetModelClass::create($insertData);
                    
                    // Attach hrbliz_biometric_id attribute for client response convenience
                    if ($hrblizIdEcho !== null) {
                        $created->setAttribute('hrbliz_biometric_id', $hrblizIdEcho);
                    }

                    $insertedLogs[] = $created;
                }
            });
        } catch (\Throwable $e) {
            Log::error('DeviceLogController::store failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save device log(s): ' . $e->getMessage(),
            ], 500);
        }

        // 5. Response formatting
        if (!$isBatch) {
            if ($skippedDuplicates > 0 && empty($insertedLogs)) {
                $firstRec = $validatedRecords[0];
                $lookupId = $firstRec['raw_hrbliz_id'] ?? $firstRec['raw_bio_id'];
                
                return response()->json([
                    'success' => true,
                    'is_duplicate' => true,
                    'message' => 'Log already exists in database (duplicate skipped).',
                    'data' => $targetModelClass::where('date_time', $firstRec['date_time'])
                        ->where(function ($q) use ($lookupId) {
                            $q->where('biometric_id', $lookupId);
                        })
                        ->first(),
                ], 200);
            }

            return response()->json([
                'success' => true,
                'message' => 'Device log created successfully.',
                'data' => $insertedLogs[0] ?? null,
            ], 201);
        }

        return response()->json([
            'success' => true,
            'message' => sprintf(
                'Batch processed: %d inserted, %d skipped duplicates out of %d total.',
                count($insertedLogs),
                $skippedDuplicates,
                count($validatedRecords)
            ),
            'total_received' => count($validatedRecords),
            'inserted_count' => count($insertedLogs),
            'skipped_count'  => $skippedDuplicates,
            'data'           => $insertedLogs,
        ], 201);
    }
}
