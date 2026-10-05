<?php

namespace App\Models;

class DeviceLogsHrbliz extends DeviceLogs
{
    protected $table = "device_logs_hrbliz";

    protected $fillable = [
        'biometric_id',
        'name',
        'dtr_date',
        'date_time',
        'status',
        'is_Shifting',
        'schedule',
        'active',
        'device_name'
    ];
}
