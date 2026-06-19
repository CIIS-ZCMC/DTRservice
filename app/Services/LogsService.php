<?php

namespace App\Services;

use App\Contracts\LogsRepositoryInterface;
use App\Contracts\ScheduleRepositoryInterface;
use App\Contracts\DeviceRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            $parts = preg_split('/\s+/', trim($rawBody));
            [$biometric_id, $dtr_date, $dtr_time, $dtr_type] = $parts;
       
            $logData = [
                'biometric_id' => $biometric_id,
                'dtr_date' => $dtr_date,
                'dtr_time' => $dtr_time,
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
