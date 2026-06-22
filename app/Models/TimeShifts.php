<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeShifts extends Model
{
    protected $table = "time_shifts";

    
    protected $fillable = [
        'first_in',
        'first_out',
        'second_in',
        'second_out',
        'description'
    ];

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'time_shift_id');
    }
}
