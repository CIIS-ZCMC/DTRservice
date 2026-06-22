<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DTR extends Model
{

      protected $table = 'daily_time_records';
      protected $fillable = [
        'biometric_id',
        'first_in',
        'first_out',
        'second_in',
        'second_out',
        'dtr_date',
        'interval_req',
        'required_working_hours',
        'required_working_minutes',
        'total_working_hours',
        'total_working_minutes',
        'overtime',
        'overtime_minutes',
        'undertime',
        'undertime_minutes',
        'overall_minutes_rendered',
        'total_minutes_reg',
        'is_biometric',
        'is_time_adjustment'
    ];
}
