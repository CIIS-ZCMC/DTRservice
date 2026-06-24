<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceInformation extends Model
{
    protected $table = "attendance__information";
      protected $fillable = [
        'biometric_id',
        'name',
        'area',
        'areacode',
        'sector',
        'first_entry',
        'last_entry',
        'attendances_id',
        'email'
    ];
}
