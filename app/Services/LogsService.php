<?php

namespace App\Services;

use App\Contracts\LogsRepositoryInterface;
use App\Contracts\ScheduleRepositoryInterface;
use App\Contracts\DeviceRepositoryInterface;
use App\Models\Biometrics;
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
        $rawBody = $request->getContent();

        $this->deviceRepository->markAsConnected($clientIp);

        $this->rotateLogsIfNeeded();

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
                    $this->processLogLine($line, $clientIp);
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
    private function processLogLine(string $line, string $clientIp)
    {
        // Check if line is a biometric template push (e.g. FP PIN=493\tFID=3\tSize=612\tValid=1\tTMP=...)
        if (ZkPushParser::isBiometricTemplateLine($line)) {
            $parsedRecords = ZkPushParser::parseKeyValues($line);
            $device = $this->deviceRepository->findByIP($clientIp);
            $sourceSn = $device?->serial_number;

            foreach ($parsedRecords as $record) {
                $pin = $record['PIN'] ?? null;
                $fid = $record['Finger_ID'] ?? $record['FID'] ?? $record['FingerID'] ?? null;
                $size = $record['Size'] ?? strlen($record['Template'] ?? $record['TMP'] ?? '');
                $valid = $record['Valid'] ?? 1;
                $template = $record['Template'] ?? $record['TMP'] ?? null;

                if ($pin && $fid !== null && $template) {
                    $queuedCount = (int)($this->syncService?->syncBiometricToAll($sourceSn, 'FINGERTMP', $record) ?? 0);

                    Biometrics::saveFingerprintTemplate(
                        (int)$pin,
                        $fid,
                        $size,
                        $valid,
                        $template,
                        $clientIp,
                        $sourceSn,
                        $queuedCount
                    );
                }
            }
            return "OK";
        }

        // Check if line is a user profile push (e.g. USER PIN=493\tName=...)
        if (ZkPushParser::isUserPushLine($line)) {
            $parsedRecords = ZkPushParser::parseKeyValues($line);
            $device = $this->deviceRepository->findByIP($clientIp);
            $sourceSn = $device?->serial_number;

            foreach ($parsedRecords as $record) {
                $pin = $record['PIN'] ?? null;
                $name = $record['Name'] ?? null;
                $queuedCount = (int)($this->syncService?->syncUserToAll($sourceSn, $record) ?? 0);

                if ($pin) {
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
            return "OK";
        }

        // Parse tab-separated format.
        $parts = preg_split('/\t/', $line);
        Log::channel('device_logs')->info('Parts', ['parts' => $parts]);

        if (count($parts) < 3) {
            Log::channel('device_logs')->error('Invalid device data format', ['line' => $line]);
            return "ERROR";
        }

        // Two formats are sent by the device:
        // - ATTLOG: biometric_id \t datetime \t status \t ...
        // - OPLOG:  "OPLOG <op>" \t biometric_id \t datetime \t param \t ...
        $isOplog = false;
        $opCode = null;
        if (stripos($parts[0], 'OPLOG') === 0) {
            if (count($parts) < 4) {
                Log::channel('device_logs')->error('Invalid OPLOG data format', ['line' => $line, 'parts' => $parts]);
                return "OK";
            }
            $isOplog = true;
            if (preg_match('/OPLOG\s*(\d+)/i', $parts[0], $opMatches)) {
                $opCode = (int)$opMatches[1];
            }
            $biometric_id = $parts[1];
            $datetime = $parts[2];
            $dtr_type = $parts[3] ?? '255';
        } else {
            $biometric_id = $parts[0];
            $datetime = $parts[1];
            $dtr_type = $parts[2];
        }

        // Validate biometric_id is numeric
        if (!is_numeric($biometric_id)) {
            Log::channel('device_logs')->error('Invalid biometric_id (must be numeric)', ['biometric_id' => $biometric_id, 'line' => $line]);
            return "OK";
        }

        // Self-Healing Auto-Restore from Database
        // If an OPLOG reports deletion/modification of user (2), password (3), fingerprint (4), card (5), clear (8), privilege change/delete (9), delete admin (10), face (24), bio clear (36), user clear (71):
        if ($isOplog && $opCode !== null && in_array($opCode, [2, 3, 4, 5, 8, 9, 10, 24, 36, 71])) {
            $device = $this->deviceRepository->findByIP($clientIp);
            if ($device && !empty($device->serial_number)) {
                // Collect candidate PINs from parts[1] (operator PIN), parts[3] (target PIN), parts[4] (param)
                $candidatePins = [];
                if (isset($parts[1]) && is_numeric($parts[1]) && (int)$parts[1] > 0) {
                    $candidatePins[] = (int)$parts[1];
                }
                if (isset($parts[3]) && is_numeric($parts[3]) && (int)$parts[3] > 0) {
                    $candidatePins[] = (int)$parts[3];
                }
                if (isset($parts[4]) && is_numeric($parts[4]) && (int)$parts[4] > 0) {
                    $candidatePins[] = (int)$parts[4];
                }
                $candidatePins = array_unique($candidatePins);

                foreach ($candidatePins as $pinToRestore) {
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

        // Skip system/device operation events (biometric_id 0 is not a real user).
        // These come from OPLOG operation logs (e.g. config changes) and are not attendance.
        if ((int)$biometric_id <= 0) {
            Log::channel('device_logs')->info('Skipped system operation log (no real user)', ['line' => $line]);
            return "OK";
        }

        // Validate that the datetime part is valid
        if (!strtotime($datetime)) {
            Log::channel('device_logs')->error('Invalid datetime format', ['datetime' => $datetime, 'line' => $line]);
            return "OK";
        }

        // Validate dtr_type/status is a small numeric code (not an IP/garbage).
        // Malformed lines must be skipped so they don't truncate the status column
        // or cause the device to retry the same bad data indefinitely.
        if (!is_numeric($dtr_type) || (int)$dtr_type < 0 || (int)$dtr_type > 255) {
            Log::channel('device_logs')->error('Invalid dtr_type (status), skipping line', [
                'dtr_type' => $dtr_type,
                'line' => $line,
                'parts' => $parts,
            ]);
            return "OK";
        }

        // Standard ZKTeco attendance status codes:
        // 0=Check-In, 1=Check-Out, 2=Break-Out, 3=Break-In, 4=OT-In, 5=OT-Out
        // OPLOG entries use parts[3] as an operation parameter, not attendance status.
        // Only accept standard attendance codes for OPLOG to prevent invalid data.
        $validAttendanceCodes = [255];
        if ($isOplog && !in_array((int)$dtr_type, $validAttendanceCodes)) {
            Log::channel('device_logs')->warning('OPLOG with non-attendance status code, skipping line', [
                'dtr_type' => $dtr_type,
                'biometric_id' => $biometric_id,
                'line' => $line,
                'parts' => $parts,
            ]);
            return "OK";
        }

        // Log entries with unusual (non-standard) status codes for ATTLOG
        if (!$isOplog && !in_array((int)$dtr_type, $validAttendanceCodes)) {
            Log::channel('device_logs')->warning('Unusual dtr_type (status) code for ATTLOG', [
                'dtr_type' => $dtr_type,
                'biometric_id' => $biometric_id,
                'line' => $line,
                'parts' => $parts,
            ]);
        }

        $dateTime = \Carbon\Carbon::parse($datetime);
        $dateTimeStr = $dateTime->format('Y-m-d H:i:s');

        // Skip duplicate entries — ZKTeco devices resend logs until they get OK
        if ($this->logsRepository->logExists((int)$biometric_id, $dateTimeStr)) {
            Log::channel('device_logs')->info('Duplicate log skipped', [
                'biometric_id' => $biometric_id,
                'date_time' => $dateTimeStr,
            ]);
            return "OK";
        }

        $logData = [
            'biometric_id' => $biometric_id,
            'dtr_date' => $dateTime->format('Y-m-d'),
            'dtr_time' => $dateTime->format('H:i:s'),
            'dtr_type' => $dtr_type,
            'ip_address' => $clientIp
        ];

        //Write to DB
        $this->logsRepository->createLog($logData);

        //Write to File
        $this->logsRepository->writeToFile($logData);

        //Write to structured log table Daily
        $this->logsRepository->writeStructuredLog($logData, $line);

        return "OK";
    }

    private function rotateLogsIfNeeded(): void
    {
        $maxSize = 10 * 1024 * 1024; // 10MB
        $logs = [
            storage_path('logs/device_logs.log'),
            storage_path('logs/attendance_logs.log'),
        ];

        foreach ($logs as $logPath) {
            if (!file_exists($logPath)) {
                continue;
            }

            if (filesize($logPath) < $maxSize) {
                continue;
            }

            $date = date('Y-m-d');
            $info = pathinfo($logPath);
            $newPath = $info['dirname'] . '/' . $info['filename'] . '_' . $date . '.' . $info['extension'];

            rename($logPath, $newPath);
        }
    }
}
