<?php

namespace App\Services;

use App\Contracts\LogsRepositoryInterface;
use App\Contracts\ScheduleRepositoryInterface;
use App\Contracts\DeviceRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LogsService
{
    public function __construct(
        protected LogsRepositoryInterface $logsRepository,
        protected ScheduleRepositoryInterface $scheduleRepository,
        protected DeviceRepositoryInterface $deviceRepository
    ) {}


    public function storeLog(Request $request): string
    {
        $clientIp = $request->ip();
        $rawBody = $request->getContent();

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
                    $this->processLogLine($line, $clientIp);
                } catch (\Throwable $th) {
                    Log::channel('device_logs')->error('Error processing device log line', [
                        'error' => $th->getMessage(),
                        'line' => $line,
                    ]);
                    // Return error to trigger device retry
                    return response("ERROR", 500)
                        ->header('Content-Type', 'text/plain');
                }
            }
        }

        return response("OK", 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Parse and persist a single device log line.
     */
    private function processLogLine(string $line, string $clientIp): void
    {
        // Parse tab-separated format.
        $parts = preg_split('/\t/', $line);

        if (count($parts) < 3) {
            Log::channel('device_logs')->error('Invalid device data format', ['line' => $line]);
            return;
        }

        // Two formats are sent by the device:
        // - ATTLOG: biometric_id \t datetime \t status \t ...
        // - OPLOG:  "OPLOG <op>" \t biometric_id \t datetime \t param \t ...
        $isOplog = false;
        if (stripos($parts[0], 'OPLOG') === 0) {
            if (count($parts) < 4) {
                Log::channel('device_logs')->error('Invalid OPLOG data format', ['line' => $line, 'parts' => $parts]);
                return;
            }
            $isOplog = true;
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
            return;
        }

        // Skip system/device operation events (biometric_id 0 is not a real user).
        // These come from OPLOG operation logs (e.g. config changes) and are not attendance.
        if ((int)$biometric_id <= 0) {
            Log::channel('device_logs')->info('Skipped system operation log (no real user)', ['line' => $line]);
            return;
        }

        // Validate that the datetime part is valid
        if (!strtotime($datetime)) {
            Log::channel('device_logs')->error('Invalid datetime format', ['datetime' => $datetime, 'line' => $line]);
            return;
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
            return;
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
            return;
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
            return;
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
    }


   
}
