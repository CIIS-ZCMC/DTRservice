<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveApplication extends Model
{
    protected $table = 'leave_applications';

    protected $fillable = [
        'employee_profile_id',
        'leave_type_id',
        'reason',
        'status',
        'date_from',
        'date_to',
    ];

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
}
