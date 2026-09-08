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

                    if (!$isIdentical) {
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
