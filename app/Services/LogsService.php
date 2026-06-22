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
        if(!empty($rawBody)) {
            // Parse tab-separated format: biometric_id\tdate_time\tdtr_type\t...
            $parts = preg_split('/\t/', trim($rawBody));

            if (count($parts) < 3) {
                Log::channel('device_logs')->error('Invalid device data format', ['body' => $rawBody]);
                return "OK";
            }

            $biometric_id = $parts[0];
            $dateTime = \Carbon\Carbon::parse($parts[1]);
            $dtr_type = $parts[2];

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
            $this->logsRepository->writeStructuredLog($logData);

        }
        return "OK";
    }


   
}
