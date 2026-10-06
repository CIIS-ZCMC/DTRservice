<?php

namespace App\Services;

use App\Contracts\LogsRepositoryInterface;
use App\Contracts\ScheduleRepositoryInterface;
use App\Contracts\DeviceRepositoryInterface;
use App\Models\Biometrics;
use App\Models\Devices;
use App\Services\OperationLogger;
use App\Services\RegistrationLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LogsService
{
    public function __construct(
        protected LogsRepositoryInterface $logsRepository,
        protected ScheduleRepositoryInterface $scheduleRepository,
        protected DeviceRepositoryInterface $deviceRepository,
        protected ?BiometricSyncService $syncService = null
    ) {
        $this->syncService = $syncService ?? app(BiometricSyncService::class);
    }


    public function storeLog(Request $request): string
    {
        $clientIp = $request->ip();
        $sourceSn = $request->input('SN') ?? $request->query('SN') ?? $request->header('SN') ?? $request->header('X-Device-SN');
        $rawBody = $request->getContent();
        $table = strtoupper((string)($request->input('table') ?? $request->query('table', '')));

        $this->deviceRepository->markAsConnected($clientIp);

        if (!empty($rawBody)) {
            // Device may push multiple records in a single request, separated by newlines.
            // Process each line individually so past/unsaved logs are not skipped.
            $lines = preg_split('/\r\n|\r|\n/', trim($rawBody));

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                try {
                    $this->processLogLine($line, $clientIp, $sourceSn, $table);
                } catch (\Throwable $th) {
                    Log::channel('device_logs')->error('Error processing device log line', [
                        'error' => $th->getMessage(),
                        'line' => $line,
                    ]);
                }
            }
        }

        return response("OK", 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Parse and persist a single device log line.
     */
    private function processLogLine(string $line, string $clientIp, ?string $requestSn = null, ?string $table = null)
    {
        // Check if line is a biometric template push (e.g. FP PIN=493\tFID=3\tSize=612\tValid=1\tTMP=...)
        if (ZkPushParser::isBiometricTemplateLine($line)) {
            $device = (!empty($requestSn) ? Devices::where('serial_number', $requestSn)->first() : null)
                ?? $this->deviceRepository->findByIP($clientIp);
            $isHrbliz = $device && (bool)$device->is_hrbliz;

            if ($isHrbliz) {
                // HRBLIZ devices only send attendance or DTR.
                return "OK";
            }

            $parsedRecords = ZkPushParser::parseKeyValues($line);
            $sourceSn = $requestSn ?? $device?->serial_number;

            foreach ($parsedRecords as $record) {
                $pin = ZkPushParser::resolveEmployeePin($record);
                $fid = $record['Finger_ID'] ?? $record['FID'] ?? $record['FingerID'] ?? null;
                $size = $record['Size'] ?? strlen($record['Template'] ?? $record['TMP'] ?? '');
                $valid = $record['Valid'] ?? 1;
                $template = $record['Template'] ?? $record['TMP'] ?? null;

                if ($pin && $fid !== null && $template) {
                    $isIdentical = Biometrics::isFingerprintIdentical($pin, $fid, $template, $isHrbliz);
                    if (!$isIdentical) {
                        $queuedCount = (int)($this->syncService?->syncBiometricToAll($sourceSn, 'FINGERTMP', $record) ?? 0);

                        Biometrics::saveFingerprintTemplate(
                            (int)$pin,
                            $fid,
                            $size,
                            $valid,
                            $template,
                            $clientIp,
                            $sourceSn,
                            $queuedCount,
                            $isHrbliz
                        );
                    }
                }
            }
            return "OK";
        }

        // Check if line is a user profile push (e.g. USER PIN=493\tName=...)
        if (ZkPushParser::isUserPushLine($line)) {
            $device = (!empty($requestSn) ? Devices::where('serial_number', $requestSn)->first() : null)
                ?? $this->deviceRepository->findByIP($clientIp);
            $isHrbliz = $device && (bool)$device->is_hrbliz;

            if ($isHrbliz) {
                // HRBLIZ devices only send attendance or DTR.
                return "OK";
            }

            $parsedRecords = ZkPushParser::parseKeyValues($line);
            $sourceSn = $requestSn ?? $device?->serial_number;

            foreach ($parsedRecords as $record) {
                $pin = ZkPushParser::resolveEmployeePin($record);
                $name = $record['Name'] ?? null;
                $pri = $record['Pri'] ?? $record['pri'] ?? $record['Privilege'] ?? null;

                if ($pin) {
                    $isIdentical = Biometrics::isUserIdentical($pin, $record, $isHrbliz);

                    if ($pri !== null && \Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
                        $devAdmin = ((int)$pri === 1 || (int)$pri === 14) ? 1 : 0;
                        $bioRecord = Biometrics::findByDevicePin($pin, $isHrbliz);
                        if ($bioRecord && (int)$bioRecord->privilege !== $devAdmin) {
                            $bioRecord->update(['privilege' => $devAdmin]);
                            $isIdentical = false;
                        }
                    }

                    $incomingGrp = $record['Grp'] ?? $record['grp'] ?? $record['Group'] ?? null;
                    $incomingTz = $record['TZ'] ?? $record['Tz'] ?? $record['Timezone'] ?? null;

                    // Group must be strictly 1 and TZ must be strictly 1 for 24/7 attendance access.
                    // Any explicit non-1 value (e.g. 0, 129, 0000000100000000) triggers timezone/group correction.
                    $isGrpInvalid = ($incomingGrp !== null && trim((string)$incomingGrp) !== '1');
                    $isTzInvalid = ($incomingTz !== null && trim((string)$incomingTz) !== '1');
                    $needsTimezoneFix = $isGrpInvalid || $isTzInvalid;

                    if (!$isIdentical || $needsTimezoneFix) {
                        $queuedCount = (int)($this->syncService?->syncUserToAll($sourceSn, $record) ?? 0);
                        RegistrationLogger::logUserRegistration(
                            $pin,
                            $name,
                            $record,
                            $clientIp,
                            $sourceSn,
                            $queuedCount
                        );
                    }
                }
            }
            return "OK";
        }

        // Check if line is a device handshake / configuration parameter line (e.g. ~ZKFPVersion=10, FPVersion=9, ~DeviceName=...)
        if (preg_match('/(?:~ZKFPVersion|FPVersion|ZKFPVersion)\s*=\s*([0-9]+)/i', $line, $fpMatches)) {
            $fpVer = ((int)$fpMatches[1] === 9) ? 'v9' : 'v10';
            $device = $this->deviceRepository->findByIP($clientIp);
            if ($device) {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'fp_version')) {
                        $device->update(['fp_version' => $fpVer]);
                        Log::channel('device_logs')->info("Updated device {$device->serial_number} fingerprint algorithm version to {$fpVer}");
                    }
                } catch (\Throwable) {
                }
            }
            return "OK";
        }

        if (str_contains($line, '=') && !str_contains($line, "\t")) {
            // Standalone key-value option or config echo (e.g. ~DeviceName=..., Stamp=..., etc.)
            Log::channel('device_logs')->debug('Device config line received: ' . $line);
            return "OK";
        }

        // Check if line is a device operation log (OPLOG / OPERLOG or pushed under table=OPERLOG/OPLOG)
        if (ZkPushParser::isOperationLog($line, $table)) {
            $this->processOperationLogLine($line, $clientIp, $requestSn, $table);
            return "OK";
        }

        // Parse tab-separated format for attendance punches
        $parts = preg_split('/\t/', $line);
        Log::channel('device_logs')->debug('Parts', ['parts' => $parts]);

        if (count($parts) < 3) {
            Log::channel('device_logs')->error('Invalid device data format', ['line' => $line]);
            return "ERROR";
        }

        $biometric_id = $parts[0];
        $datetime = $parts[1];
        $dtr_type = $parts[2];

        // Validate biometric_id is numeric and a valid real user (PIN <= 0 is a system/operation event)
        if (!is_numeric($biometric_id) || (int)$biometric_id <= 0) {
            OperationLogger::logOperation(
                opCode: 0,
                operatorPin: $biometric_id,
                opDateTime: $datetime,
                params: array_slice($parts, 2),
                rawLine: $line,
                ipAddress: $clientIp,
                deviceSn: $requestSn,
                table: $table
            );
            return "OK";
        }

        // Validate that the datetime part is valid
        if (!strtotime($datetime)) {
            Log::channel('device_logs')->error('Invalid datetime format', ['datetime' => $datetime, 'line' => $line]);
            return "OK";
        }

        // Validate dtr_type/status is a small numeric code (not an IP/garbage).
        if (!is_numeric($dtr_type) || (int)$dtr_type < 0 || (int)$dtr_type > 255) {
            Log::channel('device_logs')->error('Invalid dtr_type (status), skipping line', [
                'dtr_type' => $dtr_type,
                'line' => $line,
                'parts' => $parts,
            ]);
            return "OK";
        }

        // Standard ZKTeco / HRBLIZ / UMIS attendance status codes:
        // 0=Check-In, 1=Check-Out, 2=Break-Out, 3=Break-In, 4=OT-In, 5=OT-Out, 255=Global/Undefined
        $validAttendanceCodes = [0, 1, 2, 3, 4, 5, 255];
        if (!in_array((int)$dtr_type, $validAttendanceCodes)) {
            Log::channel('device_logs')->warning('Unusual dtr_type (status) code for ATTLOG', [
                'dtr_type' => $dtr_type,
                'biometric_id' => $biometric_id,
                'line' => $line,
                'parts' => $parts,
            ]);
        }

        $device = (!empty($requestSn) ? Devices::where('serial_number', $requestSn)->first() : null)
            ?? $this->deviceRepository->findByIP($clientIp);
        $isHrbliz = $device && (bool)$device->is_hrbliz;
        $rawPin = (int)$biometric_id;
        $targetPin = $rawPin;

        // Dynamic resolution based on device is_hrbliz marking
        $matchedBio = Biometrics::findByDevicePin($rawPin, $isHrbliz);
        if ($matchedBio && !empty($matchedBio->biometric_id)) {
            $targetPin = (int)$matchedBio->biometric_id;
        }

        $dateTime = \Carbon\Carbon::parse($datetime);
        $dateTimeStr = $dateTime->format('Y-m-d H:i:s');

        // Skip duplicate entries — check with both resolved canonical PIN and incoming raw PIN
        if ($this->logsRepository->logExists($targetPin, $dateTimeStr) ||
            ($targetPin !== $rawPin && $this->logsRepository->logExists($rawPin, $dateTimeStr))) {
            Log::channel('device_logs')->info('Duplicate log skipped', [
                'biometric_id' => $targetPin,
                'raw_pin' => $rawPin,
                'is_hrbliz' => $isHrbliz,
                'date_time' => $dateTimeStr,
            ]);
            return "OK";
        }

        $logData = [
            'biometric_id' => $targetPin,
            'raw_biometric_id' => $rawPin,
            'is_hrbliz' => $isHrbliz,
            'dtr_date' => $dateTime->format('Y-m-d'),
            'dtr_time' => $dateTime->format('H:i:s'),
            'dtr_type' => $dtr_type,
            'ip_address' => $clientIp,
            'serial_number' => $requestSn ?? $device?->serial_number,
            'device_name' => $device?->device_name,
        ];

        //Write to DB
        $this->logsRepository->createLog($logData);

        //Write to File
        $this->logsRepository->writeToFile($logData);

        //Write to structured log table Daily
        $this->logsRepository->writeStructuredLog($logData, $line);

        return "OK";
    }

    /**
     * Process and record a device operation log line.
     * Operation logs are logged to dedicated operation log files and never stored as attendance logs.
     */
    protected function processOperationLogLine(string $line, string $clientIp, ?string $requestSn = null, ?string $table = null): void
    {
        $parts = preg_split('/\t/', $line);

        $opCode = null;
        $operatorPin = null;
        $opDateTime = null;
        $params = [];

        $isTableOplog = !empty($table) && in_array(strtoupper(trim($table)), ['OPERLOG', 'OPLOG']);

        if (preg_match('/^(?:OPLOG|OPERLOG)\s*[:\s]?\s*(\d+)/i', $parts[0], $opMatches)) {
            // E.g. "OPLOG 4\t1001\t2026-10-06 08:30:00\t0\t0"
            $opCode = (int)$opMatches[1];
            $operatorPin = $parts[1] ?? '0';
            $opDateTime = $parts[2] ?? null;
            $params = array_slice($parts, 3);
        } elseif (preg_match('/^(?:OPLOG|OPERLOG)$/i', trim($parts[0]))) {
            // E.g. "OPLOG\t4\t1001\t2026-10-06 08:30:00\t0\t0"
            $opCode = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
            $operatorPin = $parts[2] ?? '0';
            $opDateTime = $parts[3] ?? null;
            $params = array_slice($parts, 4);
        } elseif ($isTableOplog) {
            // Push table=OPERLOG without "OPLOG" prefix in line
            if (count($parts) >= 3 && isset($parts[2]) && strtotime($parts[2])) {
                $opCode = is_numeric($parts[0]) ? (int)$parts[0] : null;
                $operatorPin = $parts[1] ?? '0';
                $opDateTime = $parts[2];
                $params = array_slice($parts, 3);
            } elseif (count($parts) >= 2 && isset($parts[1]) && strtotime($parts[1])) {
                $operatorPin = $parts[0] ?? '0';
                $opDateTime = $parts[1];
                $opCode = is_numeric($parts[2] ?? null) ? (int)$parts[2] : null;
                $params = array_slice($parts, 3);
            } else {
                $opCode = is_numeric($parts[0]) ? (int)$parts[0] : null;
                $operatorPin = $parts[1] ?? '0';
                $opDateTime = $parts[2] ?? null;
                $params = array_slice($parts, 3);
            }
        } else {
            // Generic OPLOG prefix fallback
            $operatorPin = $parts[1] ?? '0';
            $opDateTime = $parts[2] ?? null;
            $params = array_slice($parts, 3);
        }

        // Self-Healing Auto-Restore on actual deletion/clear opcodes:
        // Delete User (2), Clear Data (8), Admin Delete User (9), Delete Admin (10),
        // Delete Face (24), Bio Clear (36), User Clear (71)
        if ($opCode !== null && in_array($opCode, [2, 8, 9, 10, 24, 36, 71])) {
            $device = (!empty($requestSn) ? Devices::where('serial_number', $requestSn)->first() : null)
                ?? $this->deviceRepository->findByIP($clientIp);

            if ($device && !empty($device->serial_number)) {
                $candidatePins = [];
                if (is_numeric($operatorPin) && (int)$operatorPin > 0) {
                    $candidatePins[] = (int)$operatorPin;
                }
                foreach ($params as $param) {
                    if (is_numeric($param) && (int)$param > 0 && !strtotime($param)) {
                        $candidatePins[] = (int)$param;
                    }
                }
                $candidatePins = array_unique($candidatePins);

                $commandService = app(\App\Services\DeviceCommandService::class);
                foreach ($candidatePins as $pinToRestore) {
                    if ($commandService->hasPendingUserCommand($device->serial_number, $pinToRestore)) {
                        continue;
                    }

                    if ($commandService->hasPendingCommand($device->serial_number, "DATA DELETE USER\tPIN={$pinToRestore}") ||
                        $commandService->hasPendingCommand($device->serial_number, "DATA DELETE USER PIN={$pinToRestore}")) {
                        continue;
                    }

                    $bioUser = null;
                    if (\Illuminate\Support\Facades\Schema::hasTable('biometrics')) {
                        $bioUser = Biometrics::where('biometric_id', $pinToRestore)->first();
                    }
                    if ($bioUser && !empty($bioUser->biometric) && $bioUser->biometric !== 'NOT_YET_REGISTERED') {
                        $queuedCount = (int)($this->syncService?->syncUserAndTemplatesToDevice($device->serial_number, $pinToRestore) ?? 0);
                        RegistrationLogger::logAutoRestore($pinToRestore, $bioUser->name, $device->serial_number, $clientIp, $opCode, $queuedCount);
                    }
                }
            }
        }

        // Log operation to dedicated log files (Monolog channel + daily text audit file)
        OperationLogger::logOperation(
            opCode: $opCode,
            operatorPin: $operatorPin,
            opDateTime: $opDateTime,
            params: $params,
            rawLine: $line,
            ipAddress: $clientIp,
            deviceSn: $requestSn,
            table: $table
        );
    }
}
