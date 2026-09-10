<?php

namespace App\Http\Controllers;

use App\Contracts\LogsRepositoryInterface;
use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\BiometricSyncService;
use App\Services\DeviceCommandService;
use App\Services\DeviceService;
use App\Services\LogsService;
use App\Services\RegistrationLogger;
use App\Services\ZkPushParser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DeviceController extends Controller
{
    
    public function __construct( 
        protected DeviceService $deviceService,
        protected LogsService $logsService,
        protected BiometricSyncService $syncService,
        protected ?DeviceCommandService $commandService = null
    ){
        $this->commandService = $commandService ?? app(DeviceCommandService::class);
    }

    /**
     * Render the Biometric Device Management web view
     */
    public function managementView()
    {
        $totalDevices = Devices::count();
        $onlineDevices = Devices::where('last_seen_at', '>=', now()->subMinutes(2))->count();
        $offlineDevices = $totalDevices - $onlineDevices;
        $registeringDevices = Devices::where('is_registration', 1)->count();
        $operatingDevices = $totalDevices - $registeringDevices;
        $attendanceDevices = Devices::where('for_attendance', 1)->count();
        $availabilityRate = $totalDevices > 0 ? round(($onlineDevices / $totalDevices) * 100, 1) : 0;

        return view('devices.index', compact(
            'totalDevices',
            'onlineDevices',
            'offlineDevices',
            'registeringDevices',
            'operatingDevices',
            'attendanceDevices',
            'availabilityRate'
        ));
    }

    /**
     * Get paginated and filtered list of devices with rich KPI stats
     */
    public function getPaginatedDevices(Request $request): JsonResponse
    {
        try {
            $query = Devices::query();

            // Search filter
            if ($search = trim((string)$request->input('search', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('device_name', 'like', "%{$search}%")
                      ->orWhere('ip_address', 'like', "%{$search}%")
                      ->orWhere('serial_number', 'like', "%{$search}%")
                      ->orWhere('mac_address', 'like', "%{$search}%");
                });
            }

            // Status filter
            $status = $request->input('status', 'all');
            if ($status === 'online') {
                $query->whereNotNull('last_seen_at')
                      ->where('last_seen_at', '>=', now()->subMinutes(2));
            } elseif ($status === 'offline') {
                $query->where(function ($q) {
                    $q->whereNull('last_seen_at')
                      ->orWhere('last_seen_at', '<', now()->subMinutes(2));
                });
            }

            // Device type / role filter
            $type = $request->input('type', 'all');
            if ($type === 'operating') {
                $query->where('is_registration', 0);
            } elseif ($type === 'registering') {
                $query->where('is_registration', 1);
            } elseif ($type === 'attendance') {
                $query->where('for_attendance', 1);
            } elseif ($type === 'non_attendance') {
                $query->where('for_attendance', 0);
            }

            // Active filter
            $active = $request->input('active', 'all');
            if ($active === '1' || $active === 'true') {
                $query->where('is_active', 1);
            } elseif ($active === '0' || $active === 'false') {
                $query->where('is_active', 0);
            }

            // Attendance filter
            $attendance = $request->input('attendance', 'all');
            if ($attendance === '1' || $attendance === 'true') {
                $query->where('for_attendance', 1);
            } elseif ($attendance === '0' || $attendance === 'false') {
                $query->where('for_attendance', 0);
            }

            // Dynamic Sorting
            $sortBy = $request->input('sort_by', 'id');
            $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
            $allowedSort = ['id', 'device_name', 'ip_address', 'last_seen_at', 'is_active', 'is_registration'];
            if (in_array($sortBy, $allowedSort)) {
                $query->orderBy($sortBy, $sortDir);
            } else {
                $query->orderBy('id', 'asc');
            }

            // KPI Stats calculated across entire device inventory
            $totalDevices = Devices::count();
            $onlineDevices = Devices::where('last_seen_at', '>=', now()->subMinutes(2))->count();
            $offlineDevices = $totalDevices - $onlineDevices;
            $registeringDevices = Devices::where('is_registration', 1)->count();
            $operatingDevices = $totalDevices - $registeringDevices;
            $attendanceDevices = Devices::where('for_attendance', 1)->count();
            $availabilityRate = $totalDevices > 0 ? round(($onlineDevices / $totalDevices) * 100, 1) : 0;

            $perPage = $request->input('per_page', 10);
            if ($perPage === 'all' || (int)$perPage <= 0 || (int)$perPage > 200) {
                $items = $query->get();
                $totalCount = $items->count();
                $mapped = $items->map(fn($d) => $this->formatDeviceItem($d));
                $result = [
                    'data' => $mapped,
                    'meta' => [
                        'current_page' => 1,
                        'per_page' => $totalCount,
                        'total' => $totalCount,
                        'last_page' => 1,
                        'from' => $totalCount > 0 ? 1 : 0,
                        'to' => $totalCount,
                    ],
                ];
            } else {
                $paginator = $query->paginate((int)$perPage);
                $mapped = collect($paginator->items())->map(fn($d) => $this->formatDeviceItem($d));
                $result = [
                    'data' => $mapped,
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                        'last_page' => $paginator->lastPage(),
                        'from' => $paginator->firstItem(),
                        'to' => $paginator->lastItem(),
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'meta' => $result['meta'],
                'stats' => [
                    'total' => $totalDevices,
                    'online' => $onlineDevices,
                    'offline' => $offlineDevices,
                    'registering' => $registeringDevices,
                    'operating' => $operatingDevices,
                    'attendance' => $attendanceDevices,
                    'availability_rate' => $availabilityRate,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format a device model into a rich representation
     */
    protected function formatDeviceItem(Devices $device): array
    {
        $isOnline = $device->isOnline();
        return [
            'id' => $device->id,
            'device_id' => $device->device_id,
            'device_name' => $device->device_name,
            'serial_number' => $device->serial_number,
            'mac_address' => $device->mac_address,
            'ip_address' => $device->ip_address,
            'soap_port' => $device->soap_port ?? '80',
            'udp_port' => $device->udp_port ?? '4370',
            'com_key' => $device->com_key ?? '0',
            'is_active' => (bool)$device->is_active,
            'is_registration' => (bool)$device->is_registration,
            'for_attendance' => (bool)$device->for_attendance,
            'receiver_by_default' => (bool)($device->receiver_by_default ?? false),
            'is_online' => $isOnline,
            'connection_status' => $isOnline ? 'online' : 'offline',
            'last_seen_at' => $device->last_seen_at ? $device->last_seen_at->toDateTimeString() : null,
            'last_seen_human' => $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'Never',
            'last_cleared_at' => $device->last_cleared_at ? $device->last_cleared_at->toDateTimeString() : null,
            'created_at' => $device->created_at ? $device->created_at->toDateTimeString() : null,
        ];
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
     * Test connection to a single device (TCP socket + TAD/Push diagnostics)
     */
    public function testConnection(int $id): JsonResponse
    {
        try {
            $result = $this->deviceService->testDeviceConnection($id);
            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alias for testConnection matching legacy status route
     */
    public function status(int $id): JsonResponse
    {
        return $this->testConnection($id);
    }

    /**
     * Test connection across all devices in inventory
     */
    public function testAllConnections(): JsonResponse
    {
        try {
            @set_time_limit(180);
            $devices = Devices::all();
            $results = [];
            $onlineCount = 0;
            $offlineCount = 0;

            foreach ($devices as $device) {
                $res = $this->deviceService->testDeviceConnection($device);
                $results[$device->id] = $res;
                if ($res['is_online']) {
                    $onlineCount++;
                } else {
                    $offlineCount++;
                }
            }

            return response()->json([
                'success' => true,
                'total' => count($devices),
                'online' => $onlineCount,
                'offline' => $offlineCount,
                'data' => $results,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update device friendly name and/or operational roles (is_registration, for_attendance, is_active).
     * All hardware and network parameters remain locked as read-only.
     */
    public function updateName(Request $request, int $id): JsonResponse
    {
        try {
            $device = Devices::find($id);
            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => "Device [ID: {$id}] not found",
                ], 404);
            }

            $validated = $request->validate([
                'device_name' => 'nullable|string|min:1|max:100',
                'is_registration' => 'nullable|boolean',
                'for_attendance' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
            ]);

            $updates = [];
            $changesDesc = [];

            if ($request->filled('device_name')) {
                $newName = trim($validated['device_name']);
                if ($newName !== $device->device_name) {
                    $updates['device_name'] = $newName;
                    $changesDesc[] = "name to '{$newName}'";
                }
            }

            if ($request->has('is_registration')) {
                $isReg = (bool)$request->input('is_registration');
                if ($isReg !== (bool)$device->is_registration) {
                    $updates['is_registration'] = $isReg;
                    $changesDesc[] = $isReg ? 'set as Registering terminal' : 'set as Operating terminal';
                }
            }

            if ($request->has('for_attendance')) {
                $forAtt = (bool)$request->input('for_attendance');
                if ($forAtt !== (bool)$device->for_attendance) {
                    $updates['for_attendance'] = $forAtt;
                    $changesDesc[] = $forAtt ? 'enabled Attendance capture' : 'disabled Attendance capture';
                }
            }

            if ($request->has('is_active')) {
                $isActive = (bool)$request->input('is_active');
                if ($isActive !== (bool)$device->is_active) {
                    $updates['is_active'] = $isActive;
                    $changesDesc[] = $isActive ? 'activated terminal' : 'deactivated terminal';
                }
            }

            if (!empty($updates)) {
                $device->update($updates);
                $summary = implode(', ', $changesDesc);
                Log::channel('device_logs')->info("Device [ID: {$id}] updated: {$summary}");
                $message = "Device updated successfully: {$summary}";
            } else {
                $message = "No changes detected for device";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $this->formatDeviceItem($device->fresh()),
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'success' => false,
                'message' => $ve->validator->errors()->first() ?? 'Invalid parameters',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update device operational role flags (is_registration, for_attendance, is_active).
     */
    public function updateRoles(Request $request, int $id): JsonResponse
    {
        return $this->updateName($request, $id);
    }

    /**
     * Legacy UMIS compatibility endpoint for updating device status flags
     */
    public function updateDeviceStatusLegacy(Request $request): JsonResponse
    {
        try {
            $id = $request->input('id');
            $field = $request->input('field');
            $value = $request->input('value');

            $allowedFields = ['is_active', 'is_registration', 'for_attendance', 'receiver_by_default'];
            if (!in_array($field, $allowedFields)) {
                return response()->json(['message' => "Field '{$field}' cannot be updated"], 422);
            }

            $device = Devices::find($id);
            if (!$device) {
                return response()->json(['message' => "Device not found"], 404);
            }

            $device->update([$field => (bool)$value]);
            return response()->json([
                'success' => true,
                'message' => 'Device status updated successfully',
                'data' => $this->formatDeviceItem($device->fresh())
            ]);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 500);
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
            $result = $this->deviceService->syncDeviceTime($id);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Device time synced successfully',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync time across all active devices
     */
    public function syncAllTime(): JsonResponse
    {
        try {
            @set_time_limit(180);
            $devices = Devices::active()->get();
            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($devices as $device) {
                try {
                    $res = $this->deviceService->syncDeviceTime($device->id);
                    $results[] = $res;
                    $successCount++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'device_id' => $device->id,
                        'device_name' => $device->device_name,
                        'success' => false,
                        'message' => $e->getMessage(),
                    ];
                    $failCount++;
                }
            }

            return response()->json([
                'success' => true,
                'total' => $devices->count(),
                'successful' => $successCount,
                'failed' => $failCount,
                'data' => $results,
                'message' => "Time synchronization dispatched to {$successCount} device(s)",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
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
            $result = $this->deviceService->restartDevice($id);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Device restarted successfully',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restart multiple devices in batch
     */
    public function restartBatch(Request $request): JsonResponse
    {
        try {
            $ids = $request->input('device_ids', []);
            if (empty($ids) || !is_array($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No devices specified for restart',
                ], 422);
            }

            $results = [];
            $successCount = 0;
            $failCount = 0;

            foreach ($ids as $id) {
                try {
                    $res = $this->deviceService->restartDevice((int)$id);
                    $results[] = $res;
                    $successCount++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'device_id' => (int)$id,
                        'success' => false,
                        'message' => $e->getMessage(),
                    ];
                    $failCount++;
                }
            }

            return response()->json([
                'success' => true,
                'total' => count($ids),
                'successful' => $successCount,
                'failed' => $failCount,
                'data' => $results,
                'message' => "Restart command dispatched to {$successCount} device(s)",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually pull attendance logs from active devices or a specific device
     */
    public function pullLogs(Request $request): JsonResponse
    {
        try {
            @set_time_limit(300);

            $deviceId = $request->input('device_id') ?? $request->query('device_id');
            $options = array_filter([
                'date' => $request->input('date') ?? $request->query('date'),
                'start_date' => $request->input('start_date') ?? $request->query('start_date'),
                'end_date' => $request->input('end_date') ?? $request->query('end_date'),
                'pin' => $request->input('pin') ?? $request->query('pin') ?? $request->input('biometric_id') ?? $request->query('biometric_id'),
            ], fn($v) => $v !== null && $v !== '');

            if ($deviceId) {
                $result = $this->deviceService->pullLogsFromDevice((int)$deviceId, $options);
                return response()->json([
                    'success' => ($result['status'] ?? '') === 'success',
                    'data' => $result,
                ]);
            }

            $result = $this->deviceService->pullLogsFromActiveDevices($options);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually pull attendance logs from a specific device by ID
     */
    public function pullDeviceLogs(Request $request, int $id): JsonResponse
    {
        try {
            @set_time_limit(180);

            $options = array_filter([
                'date' => $request->input('date') ?? $request->query('date'),
                'start_date' => $request->input('start_date') ?? $request->query('start_date'),
                'end_date' => $request->input('end_date') ?? $request->query('end_date'),
                'pin' => $request->input('pin') ?? $request->query('pin') ?? $request->input('biometric_id') ?? $request->query('biometric_id'),
            ], fn($v) => $v !== null && $v !== '');

            $result = $this->deviceService->pullLogsFromDevice($id, $options);

            return response()->json([
                'success' => ($result['status'] ?? '') === 'success',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Request device(s) to resend attendance logs via ADMS HTTP push command (DATA QUERY ATTLOG)
     */
    public function requestLogResend(Request $request): JsonResponse
    {
        try {
            $deviceId = $request->input('device_id') ?? $request->query('device_id');
            $options = array_filter([
                'date' => $request->input('date') ?? $request->query('date'),
                'start_date' => $request->input('start_date') ?? $request->query('start_date'),
                'end_date' => $request->input('end_date') ?? $request->query('end_date'),
                'type' => $request->input('type') ?? $request->query('type'),
            ], fn($v) => $v !== null && $v !== '');

            if ($deviceId) {
                $result = $this->deviceService->requestLogResend((int)$deviceId, $options);
                return response()->json([
                    'success' => ($result['status'] ?? '') === 'queued',
                    'data' => $result,
                ]);
            }

            $result = $this->deviceService->requestLogResendFromActiveDevices($options);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Request a specific device to resend attendance logs by ID
     */
    public function requestDeviceLogResend(Request $request, int $id): JsonResponse
    {
        try {
            $options = array_filter([
                'date' => $request->input('date') ?? $request->query('date'),
                'start_date' => $request->input('start_date') ?? $request->query('start_date'),
                'end_date' => $request->input('end_date') ?? $request->query('end_date'),
                'type' => $request->input('type') ?? $request->query('type'),
            ], fn($v) => $v !== null && $v !== '');

            $result = $this->deviceService->requestLogResend($id, $options);

            return response()->json([
                'success' => ($result['status'] ?? '') === 'queued',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Safely clear attendance logs from active biometric devices or a specific device
     */
    public function clearAttendanceLogs(Request $request): JsonResponse
    {
        try {
            @set_time_limit(300);

            $deviceId = $request->input('device_id') ?? $request->query('device_id');
            $options = [
                'method' => strtolower($request->input('method') ?? $request->query('method', 'both')),
                'force' => filter_var($request->input('force') ?? $request->query('force', false), FILTER_VALIDATE_BOOLEAN),
                'dry_run' => filter_var($request->input('dry_run') ?? $request->query('dry_run', false), FILTER_VALIDATE_BOOLEAN),
                'skip_sync' => filter_var($request->input('skip_sync') ?? $request->query('skip_sync', false), FILTER_VALIDATE_BOOLEAN),
                'catch_up' => filter_var($request->input('catch_up') ?? $request->query('catch_up', false), FILTER_VALIDATE_BOOLEAN),
                'older_than' => (int)($request->input('older_than') ?? $request->query('older_than', 7)),
            ];

            if ($deviceId) {
                $result = $this->deviceService->clearAttendanceLogsFromDevice((int)$deviceId, $options);
                $isOk = in_array($result['status'] ?? '', ['success', 'queued', 'dry_run_success', 'skipped_offline']);
                return response()->json([
                    'success' => $isOk,
                    'data' => $result,
                ]);
            }

            $result = $this->deviceService->clearAttendanceLogsFromActiveDevices($options);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Safely clear attendance logs from a specific biometric device by ID
     */
    public function clearDeviceAttendanceLogs(Request $request, int $id): JsonResponse
    {
        try {
            @set_time_limit(180);

            $options = [
                'method' => strtolower($request->input('method') ?? $request->query('method', 'both')),
                'force' => filter_var($request->input('force') ?? $request->query('force', false), FILTER_VALIDATE_BOOLEAN),
                'dry_run' => filter_var($request->input('dry_run') ?? $request->query('dry_run', false), FILTER_VALIDATE_BOOLEAN),
                'skip_sync' => filter_var($request->input('skip_sync') ?? $request->query('skip_sync', false), FILTER_VALIDATE_BOOLEAN),
            ];

            $result = $this->deviceService->clearAttendanceLogsFromDevice($id, $options);
            $isOk = in_array($result['status'] ?? '', ['success', 'queued', 'dry_run_success', 'skipped_offline']);

            return response()->json([
                'success' => $isOk,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Prune historical device logs from MySQL database older than retention period (default 1 year)
     */
    public function pruneDatabaseLogs(Request $request): JsonResponse
    {
        try {
            @set_time_limit(600);

            $years = (int)($request->input('years') ?? $request->query('years', 1));
            $days = $request->input('days') ?? $request->query('days');
            $before = $request->input('before') ?? $request->query('before');
            $chunkSize = (int)($request->input('chunk') ?? $request->query('chunk', 2000));
            $archive = filter_var($request->input('archive') ?? $request->query('archive', false), FILTER_VALIDATE_BOOLEAN);
            $dryRun = filter_var($request->input('dry_run') ?? $request->query('dry_run', false), FILTER_VALIDATE_BOOLEAN);

            if (!empty($before)) {
                $cutoffDate = \Carbon\Carbon::parse($before)->format('Y-m-d');
            } elseif ($days !== null) {
                $cutoffDate = now()->subDays((int)$days)->format('Y-m-d');
            } else {
                $cutoffDate = now()->subYears($years)->format('Y-m-d');
            }

            $maxAllowedCutoff = now()->subYear()->format('Y-m-d');
            if ($cutoffDate > $maxAllowedCutoff) {
                return response()->json([
                    'success' => false,
                    'message' => "Safety Violation: Database logs can only be cleared if they are at least 1 year before today (cutoff date cannot be newer than {$maxAllowedCutoff}).",
                ], 422);
            }

            $result = app(\App\Contracts\LogsRepositoryInterface::class)->pruneLogs(
                $cutoffDate,
                $chunkSize,
                $dryRun,
                $archive
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle request of ZKTeco device | Biometric Device
     */
    public function handleDevicePush(Request $request)
    {
        try {
            $rawSn = $request->input('SN') ?? $request->input('sn') ?? $request->query('SN') ?? $request->query('sn') ?? $request->header('SN') ?? $request->header('sn');
            $sn = $rawSn !== null ? trim((string)$rawSn) : null;

            if (!empty($sn) && $sn !== 'Fail!') {
                $this->resolveAndTouchDevice($sn, $request->ip());
            }

            // Extract path segment after /iclock/
            $rawPath = $request->route('any') ?? basename($request->path());
            $path = strtolower(explode('/', trim($rawPath, '/'))[0]);

            switch ($path) {
                case 'cdata':
                    return $this->handleCdata($request, $sn);

                case 'fdata':
                    return $this->handleFdata($request, $sn);

                case 'getrequest':
                    return $this->handleGetRequest($request, $sn);

                case 'devicecmd':
                    return $this->handleDeviceCmd($request);

                default:
                    // If no explicit path matched, default to logsService
                    return $this->logsService->storeLog($request);
            }
        } catch (\Throwable $th) {
            Log::channel('device_logs')->error('handleDevicePush error: ' . $th->getMessage(), [
                'trace' => $th->getTraceAsString(),
            ]);
            return response("OK\n", 200)->header('Content-Type', 'text/plain');
        }
    }

    /**
     * Handle /iclock/cdata pushes (attendance, user registration, biometric templates)
     */
    protected function handleCdata(Request $request, ?string $sn)
    {
        if ($request->isMethod('GET')) {
            // Handshake / options request
            return response("OK\n", 200)->header('Content-Type', 'text/plain');
        }

        $table = strtoupper($request->input('table') ?? $request->query('table', ''));
        $raw = $request->getContent();

        // User registration push
        if ($table === 'USER') {
            $records = ZkPushParser::parseKeyValues($raw);
            foreach ($records as $record) {
                $pin = $record['PIN'] ?? null;
                $name = $record['Name'] ?? null;
                $pri = $record['Pri'] ?? $record['Privilege'] ?? null;

                if ($pin) {
                    $isIdentical = Biometrics::isUserIdentical($pin, $record);

                    if ($pri !== null && \Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
                        $devAdmin = ((int)$pri === 1 || (int)$pri === 14) ? 1 : 0;
                        $bioRecord = Biometrics::where('biometric_id', $pin)->first();
                        if ($bioRecord && (int)$bioRecord->privilege !== $devAdmin) {
                            $bioRecord->update(['privilege' => $devAdmin]);
                            $isIdentical = false;
                        }
                    }

                    $incomingGrp = $record['Grp'] ?? $record['grp'] ?? $record['Group'] ?? null;
                    $incomingTz = $record['TZ'] ?? $record['Tz'] ?? $record['Timezone'] ?? null;
                    $needsTimezoneFix = ($incomingGrp !== null && (int)$incomingGrp <= 0) || ($incomingTz !== null && (int)$incomingTz <= 0);

                    if (!$isIdentical || $needsTimezoneFix) {
                        $queuedCount = (int)$this->syncService->syncUserToAll($sn, $record);
                        RegistrationLogger::logUserRegistration(
                            $pin,
                            $name,
                            $record,
                            $request->ip(),
                            $sn,
                            $queuedCount
                        );
                    }
                }
            }
            return response("OK\n", 200)->header('Content-Type', 'text/plain');
        }

        // Biometric template registration push
        if (in_array($table, ['TEMPLATEV10', 'FINGERTMP', 'BIOPHOTO', 'BIODATA', 'FACE', 'USERPIC', 'BIOPIC', 'FINGERTMPV10', 'FP', 'FPDATA', 'TEMPLATE'])) {
            $records = ZkPushParser::parseKeyValues($raw);
            foreach ($records as $record) {
                $pin = $record['PIN'] ?? null;
                if (!$pin) continue;

                // A. Face templates (BIODATA, FACE)
                if (in_array($table, ['BIODATA', 'FACE'])) {
                    $bioRecord = Biometrics::where('biometric_id', $pin)->first();
                    if ($bioRecord) {
                        $bioRecord->update(['face' => json_encode($record)]);
                    }
                    $this->syncService->syncBiometricToAll($sn, 'BIODATA', $record);
                    continue;
                }

                // B. BioPhoto / User picture (BIOPHOTO, USERPIC, BIOPIC)
                if (in_array($table, ['BIOPHOTO', 'USERPIC', 'BIOPIC'])) {
                    $bioRecord = Biometrics::where('biometric_id', $pin)->first();
                    if ($bioRecord) {
                        $bioRecord->update(['biophoto' => json_encode($record)]);
                    }
                    $this->syncService->syncBiometricToAll($sn, 'BIOPHOTO', $record);
                    continue;
                }

                // C. Fingerprints (FINGERTMP, TEMPLATEV10, etc.)
                $fid = $record['Finger_ID'] ?? $record['FID'] ?? $record['FingerID'] ?? null;
                $size = $record['Size'] ?? strlen($record['Template'] ?? $record['TMP'] ?? '');
                $valid = $record['Valid'] ?? 1;
                $template = $record['Template'] ?? $record['TMP'] ?? null;

                if ($fid !== null && $template) {
                    $isIdentical = Biometrics::isFingerprintIdentical($pin, $fid, $template);

                    if (!$isIdentical) {
                        $queuedCount = (int)$this->syncService->syncBiometricToAll($sn, $table, $record);
                        Biometrics::saveFingerprintTemplate(
                            (int)$pin,
                            $fid,
                            $size,
                            $valid,
                            $template,
                            $request->ip(),
                            $sn,
                            $queuedCount
                        );
                    }
                }
            }
            return response("OK\n", 200)->header('Content-Type', 'text/plain');
        }

        // Attendance and operation logs (ATTLOG, OPERLOG, OPLOG, etc.) - delegate to existing LogsService
        return $this->logsService->storeLog($request);
    }

    /**
     * Handle /iclock/fdata pushes (fingerprint biometric templates)
     */
    protected function handleFdata(Request $request, ?string $sn)
    {
        if ($request->isMethod('GET')) {
            return response("OK\n", 200)->header('Content-Type', 'text/plain');
        }

        $raw = $request->getContent();
        $records = ZkPushParser::parseKeyValues($raw);

        foreach ($records as $record) {
            $pin = $record['PIN'] ?? null;
            $fid = $record['Finger_ID'] ?? $record['FID'] ?? $record['FingerID'] ?? null;
            $size = $record['Size'] ?? strlen($record['Template'] ?? $record['TMP'] ?? '');
            $valid = $record['Valid'] ?? 1;
            $template = $record['Template'] ?? $record['TMP'] ?? null;

            if ($pin && $fid !== null && $template) {
                $isIdentical = Biometrics::isFingerprintIdentical($pin, $fid, $template);

                if (!$isIdentical) {
                    $queuedCount = (int)$this->syncService->syncBiometricToAll($sn, 'FINGERTMP', $record);
                    Biometrics::saveFingerprintTemplate(
                        (int)$pin,
                        $fid,
                        $size,
                        $valid,
                        $template,
                        $request->ip(),
                        $sn,
                        $queuedCount
                    );
                }
            }
        }

        return response("OK\n", 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Handle /iclock/getrequest polling from devices
     */
    protected function handleGetRequest(Request $request, ?string $sn)
    {
        if (empty($sn)) {
            return response("OK\n", 200)->header('Content-Type', 'text/plain');
        }

        // Fetch up to 10 pending commands at a time from file-based command storage
        $commands = $this->commandService->getPendingCommands($sn, 10);

        if (empty($commands)) {
            return response("OK\n", 200)->header('Content-Type', 'text/plain');
        }

        $responseLines = [];
        $sentIds = [];
        foreach ($commands as $cmd) {
            $responseLines[] = "C:{$cmd['id']}:{$cmd['command']}";
            $sentIds[] = $cmd['id'];
        }

        $this->commandService->markCommandsAsSent($sentIds);

        Log::channel('device_logs')->info('Dispatched commands to device', [
            'device_sn' => $sn,
            'count' => count($responseLines),
        ]);

        return response(implode("\n", $responseLines) . "\n", 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Handle /iclock/devicecmd execution results from devices
     */
    protected function handleDeviceCmd(Request $request)
    {
        $raw = $request->getContent();
        $results = ZkPushParser::parseQueryStringLines($raw);

        foreach ($results as $result) {
            if (isset($result['ID'])) {
                $returnCode = (int)($result['Return'] ?? -1);
                $this->commandService->recordCommandAck($result['ID'], $returnCode);

                Log::channel('device_logs')->info('Device command ACK received', [
                    'command_id' => $result['ID'],
                    'return_code' => $returnCode,
                    'status' => $returnCode >= 0 ? 'SUCCESS' : 'FAILED',
                ]);
            }
        }

        return response("OK\n", 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Resolve device by Serial Number or IP address, and auto-register if new.
     */
    protected function resolveAndTouchDevice(string $sn, string $ip): ?Devices
    {
        try {
            // 1. Try matching by Serial Number
            $device = Devices::where('serial_number', $sn)->first();

            if ($device) {
                $device->update([
                    'ip_address'   => $ip,
                    'last_seen_at' => now(),
                    'is_active'    => 1,
                ]);
                return $device;
            }

            // 2. If not matched by SN, match by IP address where SN is null/empty/'Fail!'
            $unboundDevice = Devices::where('ip_address', $ip)
                ->where(function ($q) {
                    $q->whereNull('serial_number')
                        ->orWhere('serial_number', '')
                        ->orWhere('serial_number', 'Fail!');
                })
                ->first();

            if ($unboundDevice) {
                $unboundDevice->update([
                    'serial_number' => $sn,
                    'last_seen_at'  => now(),
                    'is_active'     => 1,
                ]);
                Log::channel('device_logs')->info("Bound serial number {$sn} to existing device [ID: {$unboundDevice->id}, Name: {$unboundDevice->device_name}] at IP {$ip}");
                return $unboundDevice;
            }

            // 3. Completely new device plugged into the network -> Auto-register it
            $nameSuffix = strlen($sn) >= 4 ? substr($sn, -4) : $sn;
            $device = Devices::create([
                'device_name'     => 'Terminal ' . $nameSuffix,
                'serial_number'   => $sn,
                'ip_address'      => $ip,
                'com_key'         => '0',
                'soap_port'       => '80',
                'udp_port'        => '4370',
                'is_active'       => 1,
                'is_registration' => 0,
                'last_seen_at'    => now(),
            ]);

            Log::channel('device_logs')->info("Auto-registered new biometric device [SN: {$sn}, IP: {$ip}]");
            return $device;
        } catch (\Throwable $e) {
            Log::channel('device_logs')->warning("Failed to resolve/register device [SN: {$sn}, IP: {$ip}]: " . $e->getMessage());
            return null;
        }
    }
}
