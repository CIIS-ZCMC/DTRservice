<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficialTimeApplication extends Model
{
    protected $table = 'official_time_applications';

    protected $fillable = [
        'employee_profile_id',
        'status',
        'date_from',
        'date_to',
    ];
}
