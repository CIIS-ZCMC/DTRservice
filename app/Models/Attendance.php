<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = "attendances";

    public function informations()
    {
        return $this->hasMany(AttendanceInformation::class, 'attendances_id');
    }
}
