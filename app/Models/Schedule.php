<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $table = "schedules";

    
    protected $fillable = [
        'date',
        'is_weekend',
        'status',
        'remarks',
        'time_shift_id',
        'holiday_id',
    ];

    public function timeShift()
    {
        return $this->belongsTo(TimeShifts::class, 'time_shift_id');
    }

    public function employeeSchedules()
    {
        return $this->hasMany(EmployeeSchedule::class, 'schedule_id');
    }
}
