<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeAdjustment extends Model
{
    protected $table = 'time_adjustments';

    protected $fillable = [
        'employee_profile_id',
        'status',
        'date',
        'first_in',
        'first_out',
        'second_in',
        'second_out',
    ];
}
