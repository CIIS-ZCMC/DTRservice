<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveApplication extends Model
{
    protected $table = 'leave_applications';

    protected $fillable = [
        'employee_profile_id',
        'status',
        'date_from',
        'date_to',
    ];
}
